<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Inline\Abbreviation;
use MarkupCarve\Carve\Node\Inline\CaptionNumber;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\CriticComment;
use MarkupCarve\Carve\Node\Inline\Delete;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
use MarkupCarve\Carve\Node\Inline\Highlight;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\InlineExtension;
use MarkupCarve\Carve\Node\Inline\InlineFootnote;
use MarkupCarve\Carve\Node\Inline\Insert;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\LiteralInline;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Node\Inline\RawInline;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Strike;
use MarkupCarve\Carve\Node\Inline\Strong;
use MarkupCarve\Carve\Node\Inline\Subscript;
use MarkupCarve\Carve\Node\Inline\Substitution;
use MarkupCarve\Carve\Node\Inline\Superscript;
use MarkupCarve\Carve\Node\Inline\Symbol;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Inline\Underline;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Parser\Utility\BracketScanner;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * Inline parser for Djot
 *
 * Handles emphasis, strong, links, images, code spans, etc.
 */
class InlineParser
{
    /**
     * @var array<array{type: string, char: string, pos: int, node: \MarkupCarve\Carve\Node\Node}>
     */
    protected array $delimiterStack = [];

    /**
     * Current source line number for error reporting (0-indexed)
     */
    protected int $currentLine = 0;

    /**
     * Byte offsets of every line ending in the text the BLOCK was handed.
     *
     * Built once per block, and only when diagnostics are being collected, so
     * a diagnostic's coordinates cost a search rather than a rescan. Counting
     * the newlines before the position each time is quadratic in the number of
     * diagnostics, which is the shape a document of faults has.
     *
     * @var list<int>
     */
    protected array $lineBreakOffsets = [];

    /**
     * Where the text currently being parsed starts in the block's text.
     *
     * A NESTED parse - a link's text, an emphasis body - is handed a substring
     * and restarts its cursor at 0, so counting within that substring alone
     * loses every line before it and reports the fault too high in the
     * document. The origin accumulates instead, exactly as the source map's
     * shift does, so a position is always resolved against the whole block.
     */
    protected int $textOrigin = 0;

    /**
     * The source line and 1-based column of `$pos` in the text being parsed.
     *
     * `$currentLine` is the line the BLOCK starts on, and a block can be many
     * lines: a folded paragraph, a line block's whole stanza. Reporting it for
     * every position puts every diagnostic in such a block on its first line,
     * and reports an offset into the block as if it were a column.
     *
     * A line block was right by construction until its stanza became a single
     * parse rather than one parse per line (markup-carve/carve-php#1327); a
     * paragraph had been reporting its own first line for far longer. One rule,
     * one place.
     *
     * @param int $pos
     *
     * @return array{0: int, 1: int}
     */
    protected function lineAndColumnAt(int $pos): array
    {
        $absolute = $this->textOrigin + max(0, $pos);
        $breaks = $this->lineBreakOffsets;
        if ($breaks === []) {
            return [$this->currentLine, $absolute + 1];
        }

        // How many line endings sit strictly before the position.
        $low = 0;
        $high = count($breaks);
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($breaks[$mid] < $absolute) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        // Before the first line ending, the position is on the block's own
        // first line and its column is the offset itself.
        $previous = $breaks[$low - 1] ?? null;
        if ($low === 0 || $previous === null) {
            return [$this->currentLine, $absolute + 1];
        }

        return [$this->currentLine + $low, $absolute - $previous];
    }

    /**
     * Custom inline patterns: array of [pattern => callback]
     * Callback receives (string $match, array $groups, InlineParser $parser)
     * and should return a Node or null
     *
     * @var array<string, callable(string, array<string>, self): ?\MarkupCarve\Carve\Node\Node>
     */
    protected array $customPatterns = [];

    /**
     * Registered scanner-function inline matchers.
     *
     * `pattern` is the source regex (when registered via addInlinePattern), used
     * to derive the trigger-byte gate. `triggerChars` is an explicit set of
     * first bytes for a raw-closure matcher (addInlineMatcher) that has no
     * pattern but still only fires on known characters.
     *
     * @var array<array{matcher: \Closure, priority: int, seq: int, pattern: string|null, triggerChars: string|null}>
     */
    protected array $inlineMatchers = [];

    protected int $inlineMatcherSeq = 0;

    /**
     * @var array<\Closure>|null
     */
    protected ?array $sortedInlineMatchers = null;

    /**
     * Inline matchers in priority order, each paired with the SET of literal
     * ASCII bytes its pattern can begin with (a map byte => true), or null when
     * it can start anywhere. Lets the per-position scan skip a matcher whose
     * trigger set does not contain the current char without running its
     * (always-failing) preg_match -- the dominant cost on plain prose.
     *
     * @var array<array{matcher: \Closure, first: array<string, true>|null}>|null
     */
    protected ?array $compiledInlineMatchers = null;

    /**
     * Reused matcher context (stateless across positions); avoids allocating
     * one per character in the inline scan.
     */
    protected ?MatcherContext $inlineMatcherContext = null;

    /**
     * Set of byte values that can begin an inline construct (escape, code,
     * emphasis, link, smart typography, a registered matcher, ...). A character
     * not in this set falls straight through to the text buffer, so the scan can
     * skip the whole per-position handler cascade for it. Null disables the
     * fast path (a matcher with no determinable first byte must run everywhere).
     *
     * @var array<string, true>|null
     */
    protected ?array $inlineSignificantBytes = null;

    protected bool $inlineSignificantComputed = false;

    /**
     * Memo for parseLink: the text last scanned for link triggers, and whether
     * it contains any `](`, `][`, or `]{`. Comparing against the same string
     * instance is pointer-cheap, so repeated parseLink calls over one text bail
     * in O(1) when no link can form (a deep `[[[[...` run would otherwise be
     * O(n^2)).
     */
    protected ?string $linkTriggerText = null;

    protected bool $linkTriggerPresent = false;

    /**
     * Memo for the closing-paren guard: the text last scanned and the position
     * of its LAST `)` (strrpos, or false when none). The link-destination scans
     * in parseLink() / findLinkDestinationEnd() run char-by-char to end-of-text
     * looking for `)`. When a `[`/`(` run has no closing paren at all, each `[`
     * would re-scan the whole tail -- O(n^2) for input like `[a](` repeated.
     * strrpos is a C-level scan computed once per text (the same string instance
     * is compared pointer-cheap), letting each scan bail in O(1) when no `)`
     * lies at or after the destination start.
     *
     * @var int|false
     */
    protected int|false $lastCloseParenPos = false;

    protected ?string $lastCloseParenText = null;

    /**
     * Memo for matchingCloseParen: maps each `(` position in the current text
     * to the `)` that balances it.
     *
     * @var array<int, int>
     */
    protected array $matchingCloseParen = [];

    protected ?string $matchingCloseParenText = null;

    /**
     * Memo for the emphasis-opener forward scan in parseDelimited(). Each `_`,
     * `*`, `~`, `=`, `/` opener scans toward end-of-text for a matching closer.
     * When the tail holds no valid closer for a delimiter (e.g. every candidate
     * is alnum-blocked, as in `_a](` repeated), that scan runs to end-of-text and
     * fails -- and every LATER opener of the same delimiter scans a strict suffix
     * of the same text, so it fails too. Without a memo, N such openers each do
     * O(n) work -> O(n^2). We record, per delimiter, the smallest start position
     * from which no closer exists; any opener at or after it bails in O(1).
     *
     * The scan is monotonic: a later opener sees the same structural skips
     * (code spans, attribute blocks, autolinks, link destinations, escapes) and
     * the same position-only closer rejections (space-before, `}`-after,
     * alnum-after). The only opener-dependent rejection is the empty-content
     * check, which fires solely for a closer immediately after the opener -- a
     * position no later opener's suffix range includes -- so the memo never turns
     * a would-be match into a miss.
     *
     * @var array<string, int>
     */
    protected array $emphNoCloseFrom = [];

    protected ?string $emphNoCloseText = null;

    /**
     * Memo for the fixed-closer tail scanners (attribute `}`, critic `+}` /
     * `-}` / `~}`, editorial `#}`, bold-italic star-slash). Each walks forward
     * to a fixed closing delimiter char-by-char; when that delimiter is absent from
     * the tail the walk runs to end-of-text and fails, so N openers each do
     * O(n) work -> O(n^2) (e.g. `{+` or `[x]{` repeated). strrpos is a C-level
     * scan run once per (text, needle); a later opener then bails in O(1) when
     * the closer cannot lie at or after its content start. Byte-identical: only
     * ever elides a scan that provably would have failed.
     *
     * @var array<string, int|false>
     */
    protected array $closerLastPos = [];

    protected ?string $closerLastText = null;

    /**
     * Memo for findLinkDestinationEnd: for the current text, `next[$p]` is the
     * index of the first UNESCAPED `)` at or after `$p` (or -1 when none). The
     * emphasis-close scan skips a link destination `](...)` by jumping to its
     * `)`; when many openers each reach a `](` whose only closer is one far `)`
     * (input `_a](` repeated + a trailing `)`), a char-by-char walk re-scans the
     * same tail per opener -> O(n^2). The table answers each jump in O(1). Null
     * when the text holds no `)` at all.
     *
     * @var array<int, int>|null
     */
    protected ?array $unescCloseParen = null;

    protected ?string $unescCloseParenText = null;

    /**
     * Memo for findAttributeEnd's closer-supply check: for the current text,
     * `suffix[$p]` is the number of `}` at index >= $p. Built lazily (only when
     * a nested `{` inside an attribute scan forces the depth check), so a
     * document of flat, valid attribute blocks never pays for it.
     *
     * @var array<int, int>|null
     */
    protected ?array $braceCloseSuffix = null;

    protected ?string $braceCloseSuffixText = null;

    /**
     * Cached abbreviation regex pattern (built once per document)
     */
    protected ?string $abbreviationPattern = null;

    /**
     * Cached abbreviation keys for the current pattern
     *
     * @var array<string, string>|null
     */
    protected ?array $cachedAbbreviations = null;

    protected bool $footnoteRecognitionEnabled = true;

    protected bool $captionContextEnabled = false;

    protected bool $captionNumberEmitted = false;

    protected bool $wordAttributesEnabled = true;

    /**
     * Smart quote characters (configurable via SmartQuotesExtension for locale support)
     */
    protected string $openDoubleQuote = "\u{201C}";

    protected string $closeDoubleQuote = "\u{201D}";

    protected string $openSingleQuote = "\u{2018}";

    protected string $closeSingleQuote = "\u{2019}";

    /**
     * Apostrophe character (always U+2019 RIGHT SINGLE QUOTATION MARK)
     *
     * Not configurable via extension — apostrophes are language-independent.
     */
    protected string $apostrophe = "\u{2019}";

    public function __construct(protected BlockParser $blockParser)
    {
    }

    /**
     * Register a custom inline pattern
     *
     * The pattern should be a regex that matches from the current position.
     * It will be anchored to the start automatically.
     *
     * Example - @mentions:
     * ```php
     * $parser->addInlinePattern('/@([a-zA-Z0-9_]+)/', function($match, $groups, $parser) {
     *     $link = new Link('https://example.com/users/' . $groups[1]);
     *     $link->appendChild(new Text('@' . $groups[1]));
     *     return $link;
     * });
     * ```
     *
     * Example - [[wiki-links]]:
     * ```php
     * $parser->addInlinePattern('/\[\[([^\]]+)\]\]/', function($match, $groups, $parser) {
     *     $link = new Link('/wiki/' . rawurlencode($groups[1]));
     *     $link->appendChild(new Text($groups[1]));
     *     return $link;
     * });
     * ```
     *
     * @param string $pattern Regex pattern (without anchors)
     * @param callable(string, array<string>, self): ?\MarkupCarve\Carve\Node\Node $callback
     */
    public function addInlinePattern(string $pattern, callable $callback): void
    {
        $this->removeInlinePattern($pattern);
        $this->customPatterns[$pattern] = $callback;

        $anchored = $pattern[0] . '\G' . substr($pattern, 1);
        $self = $this;

        $this->registerInlineMatcher(
            function (string $text, int $pos, MatcherContext $ctx) use ($anchored, $callback, $self): ?array {
                if (!preg_match($anchored, $text, $matches, 0, $pos)) {
                    return null;
                }

                $node = $callback($matches[0], $matches, $self);
                if ($node === null) {
                    return null;
                }

                return ['node' => $node, 'end' => $pos + strlen($matches[0])];
            },
            pattern: $pattern,
        );
    }

    /**
     * Remove a custom inline pattern
     */
    public function removeInlinePattern(string $pattern): void
    {
        unset($this->customPatterns[$pattern]);
        $this->inlineMatchers = array_values(array_filter(
            $this->inlineMatchers,
            static fn (array $entry): bool => $entry['pattern'] !== $pattern,
        ));
        $this->sortedInlineMatchers = null;
        $this->compiledInlineMatchers = null;
        $this->inlineSignificantComputed = false;
    }

    /**
     * Get all registered custom patterns
     *
     * @return array<string, callable>
     */
    public function getInlinePatterns(): array
    {
        return $this->customPatterns;
    }

    /**
     * Register a raw-closure inline matcher.
     *
     * A matcher with no regex pattern would otherwise run at every scan position
     * (and disable the whole-document fast-skip). Pass `$triggerChars` -- the
     * set of literal first bytes the matcher can ever fire on (e.g. `'['` for a
     * citation `[@key]`) -- to gate it to those positions. Each byte of the
     * string is treated as an independent trigger.
     *
     * @param \Closure(string, int, \MarkupCarve\Carve\Parser\MatcherContext): (array{node: \MarkupCarve\Carve\Node\Node, end: int}|null) $matcher
     * @param int $priority
     * @param string|null $triggerChars Literal first bytes this matcher fires on; null = runs everywhere
     */
    public function addInlineMatcher(Closure $matcher, int $priority = 0, ?string $triggerChars = null): void
    {
        $this->registerInlineMatcher($matcher, $priority, triggerChars: $triggerChars);
    }

    /**
     * @param \Closure(string, int, \MarkupCarve\Carve\Parser\MatcherContext): (array{node: \MarkupCarve\Carve\Node\Node, end: int}|null) $matcher
     * @param int $priority
     * @param string|null $pattern
     * @param string|null $triggerChars
     */
    protected function registerInlineMatcher(
        Closure $matcher,
        int $priority = 0,
        ?string $pattern = null,
        ?string $triggerChars = null,
    ): void {
        $this->inlineMatchers[] = [
            'matcher' => $matcher,
            'priority' => $priority,
            'seq' => $this->inlineMatcherSeq++,
            'pattern' => $pattern,
            'triggerChars' => $triggerChars,
        ];
        $this->sortedInlineMatchers = null;
        $this->compiledInlineMatchers = null;
        $this->inlineSignificantComputed = false;
    }

    /**
     * @return array<\Closure>
     */
    protected function sortedInlineMatchers(): array
    {
        if ($this->sortedInlineMatchers !== null) {
            return $this->sortedInlineMatchers;
        }

        $entries = $this->inlineMatchers;
        usort($entries, static function (array $a, array $b): int {
            return $b['priority'] <=> $a['priority'] ?: $a['seq'] <=> $b['seq'];
        });

        return $this->sortedInlineMatchers = array_map(
            static fn (array $entry): Closure => $entry['matcher'],
            $entries,
        );
    }

    /**
     * Set locale-specific smart quote characters
     *
     * Apostrophes (mid-word and before digits) always remain U+2019
     * regardless of this setting.
     */
    public function setQuoteCharacters(
        string $openDoubleQuote,
        string $closeDoubleQuote,
        string $openSingleQuote,
        string $closeSingleQuote,
    ): void {
        $this->openDoubleQuote = $openDoubleQuote;
        $this->closeDoubleQuote = $closeDoubleQuote;
        $this->openSingleQuote = $openSingleQuote;
        $this->closeSingleQuote = $closeSingleQuote;
    }

    /**
     * Get the current smart quote characters
     *
     * @return array{openDouble: string, closeDouble: string, openSingle: string, closeSingle: string}
     */
    public function getQuoteCharacters(): array
    {
        return [
            'openDouble' => $this->openDoubleQuote,
            'closeDouble' => $this->closeDoubleQuote,
            'openSingle' => $this->openSingleQuote,
            'closeSingle' => $this->closeSingleQuote,
        ];
    }

    /**
     * Parse inline content
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param string $text
     * @param int $sourceLine Source line number (0-indexed) for error reporting
     * @param bool $captionContext
     * @param \MarkupCarve\Carve\Parser\SourceMap|null $sourceMap
     */
    public function parse(
        Node $parent,
        string $text,
        int $sourceLine = 0,
        bool $captionContext = false,
        ?SourceMap $sourceMap = null,
    ): void {
        $this->sourceMap = $sourceMap;
        $this->textBufferStart = null;
        $this->textBufferRewritten = false;
        $this->delimiterStack = [];
        $this->currentLine = $sourceLine;
        $this->textOrigin = 0;
        // Only when a diagnostic can actually be kept: this is one pass over
        // the block, and the default parse has no use for it.
        $this->lineBreakOffsets = [];
        if ($this->blockParser->collectsWarnings() && str_contains($text, "\n")) {
            $offset = 0;
            while (($offset = strpos($text, "\n", $offset)) !== false) {
                $this->lineBreakOffsets[] = $offset;
                $offset++;
            }
        }
        $previousCaptionContext = $this->captionContextEnabled;
        $previousCaptionNumberEmitted = $this->captionNumberEmitted;
        $this->captionContextEnabled = $captionContext;
        if ($captionContext) {
            $this->captionNumberEmitted = false;
        }

        try {
            $this->parseInlines($parent, $text);
        } finally {
            $this->captionContextEnabled = $previousCaptionContext;
            $this->captionNumberEmitted = $previousCaptionNumberEmitted;
        }
    }

    public function parseHeading(Node $parent, string $text, int $sourceLine = 0, ?SourceMap $sourceMap = null): void
    {
        $previousWordAttributes = $this->wordAttributesEnabled;
        $this->wordAttributesEnabled = false;

        try {
            $this->parse($parent, $text, $sourceLine, sourceMap: $sourceMap);
        } finally {
            $this->wordAttributesEnabled = $previousWordAttributes;
        }
    }

    /**
     * Inline-nesting DoS guard: deeply nested inline constructs (e.g. a bomb
     * of nested links `[[[…](#)](#)…`) recurse through parseInlines and rescan
     * balanced brackets at each level, which is ~O(n^2). Beyond this depth the
     * remaining text is emitted literally instead of recursing further. Far
     * deeper than any real document; mirrors the block-nesting cap.
     *
     * @var int
     */
    protected const MAX_INLINE_DEPTH = 100;

    /**
     * Current inline-recursion depth (see self::MAX_INLINE_DEPTH).
     */
    protected int $inlineDepth = 0;

    /**
     * Re-parse inner content, keeping positions when the caller knows where the
     * substring began in the text it came from.
     */
    protected function parseInlinesAt(
        Node $parent,
        string $text,
        int $offsetInParent,
        ?bool $footnoteRecognitionEnabled = null,
    ): void {
        $outer = $this->sourceMap;
        $outerStart = $this->textBufferStart;
        $outerRewritten = $this->textBufferRewritten;

        $outerOrigin = $this->textOrigin;

        $this->sourceMap = $outer?->shifted($offsetInParent);
        $this->textBufferStart = null;
        $this->textBufferRewritten = false;
        // The same accumulation the map's shift makes, for the same reason: the
        // nested cursor restarts at 0 and its positions still have to name a
        // place in the whole block.
        $this->textOrigin = $outerOrigin + $offsetInParent;

        try {
            $this->parseInlines($parent, $text, $footnoteRecognitionEnabled);
        } finally {
            $this->sourceMap = $outer;
            $this->textBufferStart = $outerStart;
            $this->textBufferRewritten = $outerRewritten;
            $this->textOrigin = $outerOrigin;
        }
    }

    protected function parseInlines(Node $parent, string $text, ?bool $footnoteRecognitionEnabled = null): void
    {
        if ($this->inlineDepth >= self::MAX_INLINE_DEPTH) {
            // Too deeply nested (DoS guard): stop recursing and keep the
            // remaining text as a literal text node rather than re-parsing it.
            if ($text !== '') {
                $parent->appendChild(new Text($text));
            }

            return;
        }

        $this->inlineDepth++;
        try {
            $this->parseInlinesImpl($parent, $text, $footnoteRecognitionEnabled);
        } finally {
            $this->inlineDepth--;
        }
    }

    /**
     * Where the text currently buffered started in the built string, so a Text
     * node can be placed. Null when nothing is buffered.
     */
    protected ?int $textBufferStart = null;

    /**
     * Whether the buffered run contains text that is not a verbatim copy of the
     * source - a decoded escape, a smart-punctuation glyph, an internal
     * sentinel. Offsets into it would not line up, so the node gets NO span
     * rather than a wrong one (PART 12 section 4 forbids invented positions).
     */
    protected bool $textBufferRewritten = false;

    /**
     * Where the buffered run ends in the source, so a rewritten run still has a
     * measured extent to be placed by.
     */
    protected ?int $textBufferEnd = null;

    protected ?SourceMap $sourceMap = null;

    /**
     * Give an inline node its span, when it has one honestly.
     *
     * Silently does nothing without a map (positions not requested), without a
     * recorded start (the run began somewhere this parser did not observe), or
     * when the run was rewritten - none of which is a failure. PART 12 section 4
     * requires a real position or none, so declining is the correct outcome.
     */

    /**
     * Place a node from the source extent the parser consumed for it.
     *
     * For nodes whose text is NOT their source: a smart quote, an escape, a code
     * span's fence, a link's whole `[text](url)`. The parser knows exactly which
     * bytes it read, which is a better answer than any search could give.
     */
    private function placeAt(Node $node, int $start, int $end): void
    {
        if ($this->sourceMap === null) {
            return;
        }

        // A Text node's content IS its source, so it can be checked - and must
        // be. A handler that fails to recognize a construct returns the literal
        // text but still reports the end of everything it CONSUMED, including a
        // trailing attribute block that never became part of the node. That
        // produced the one wrong span this sweep has ever found:
        // `[^a]{.ref}` selected for a text node holding `[^a]`.
        if ($node instanceof Text) {
            $node->setPos($this->sourceMap->spanFor($start, $node->getContent()));

            return;
        }

        $node->setPos($this->sourceMap->spanRange($start, $end));
    }

    /**
     * The span of a run whose text the parser rewrote, when the source it
     * covers demonstrably produces that text.
     *
     * Only one rewrite of THIS layer's can survive into a buffered run: `\ `,
     * Carve's non-breaking-space form, which becomes a sentinel. A backslash
     * before ASCII punctuation produces an EscapedText NODE instead, so it
     * flushes the buffer and never reaches here. Applying the rewrite to the
     * source and comparing is a real check - the span is rejected when the
     * source does not produce the text, exactly as the equality check rejects a
     * verbatim span that selects the wrong bytes.
     *
     * The BLOCK layer may have rewritten the same run first, though, which is
     * why the source is taken from the map rather than sliced raw - see below.
     */
    private function rewrittenSpan(?int $start, ?int $end, string $text): ?SourceSpan
    {
        if ($this->sourceMap === null || $start === null || $end === null) {
            return null;
        }

        $span = $this->sourceMap->spanRange($start, $end);
        if ($span === null) {
            return null;
        }

        // THE MAP'S OWN REWRITE COMES OFF FIRST. A line block turns a preserved
        // run of spaces into sentinels before the inline layer ever sees the
        // string, so on `  a\ b` the raw source satisfies neither check: it has
        // spaces where the text has block sentinels. Asking the map to replay
        // its rewrite leaves exactly the one this layer applied, and the escape
        // check below finishes it (carve-php#1351). For a map that rewrote
        // nothing this is the raw slice, unchanged.
        $slice = $this->sourceMap->produced($start, $end);
        if ($slice === null || self::applyEscapes($slice) !== $text) {
            return null;
        }

        return $span;
    }

    /**
     * The rewrite a buffered text run can carry.
     */
    private static function applyEscapes(string $slice): string
    {
        $out = '';
        $length = strlen($slice);
        for ($i = 0; $i < $length; $i++) {
            if ($slice[$i] === '\\' && $i + 1 < $length) {
                $next = $slice[$i + 1];
                if ($next === ' ') {
                    $out .= "\u{E000}";
                    $i++;

                    continue;
                }
            }
            $out .= $slice[$i];
        }

        return $out;
    }

    private function placeInline(Node $node, ?int $start, string $text, bool $rewritten): void
    {
        if ($this->sourceMap === null || $start === null || $rewritten) {
            return;
        }

        $node->setPos($this->sourceMap->spanFor($start, $text));
    }

    /**
     * Remember where a buffered run begins, the first time anything lands in it.
     */

    /**
     * Remember where a buffered run begins and how far it has consumed.
     *
     * `$consumed` is the number of SOURCE bytes this piece took, which is not
     * the number it contributed to the buffer whenever the text was rewritten -
     * an escape reads two and writes one. Tracking the end as well as the start
     * is what lets a rewritten run still be placed: its extent is measured by
     * the parser, so no text comparison is needed or possible.
     */
    private function noteTextStart(string $buffer, int $pos, bool $rewritten = false, int $consumed = 1): void
    {
        if ($buffer === '') {
            $this->textBufferStart = $pos;
            $this->textBufferRewritten = $rewritten;
        } elseif ($rewritten) {
            $this->textBufferRewritten = true;
        }

        $this->textBufferEnd = $pos + $consumed;
    }

    protected function parseInlinesImpl(Node $parent, string $text, ?bool $footnoteRecognitionEnabled = null): void
    {
        $previousFootnoteRecognition = $this->footnoteRecognitionEnabled;
        if ($footnoteRecognitionEnabled !== null) {
            $this->footnoteRecognitionEnabled = $footnoteRecognitionEnabled;
        }

        $length = strlen($text);
        $pos = 0;
        $textBuffer = '';

        // Bytes that can start an inline construct; everything else is plain
        // text and skips the whole per-position handler cascade below.
        $sig = $this->significantInlineBytes();
        // In a caption, `#` is the number-placeholder opener (^ Figure #: …) and
        // must be scanned even when no mentions/tags matcher made it significant.
        if ($sig !== null && $this->captionContextEnabled) {
            $sig['#'] = true;
        }

        while ($pos < $length) {
            $char = $text[$pos];

            // Plain-text fast path: a byte that begins no inline construct is
            // appended directly. Byte-identical to falling through every handler
            // (all of which would decline) to the text-buffer append.
            if ($sig !== null && !isset($sig[$char])) {
                $this->noteTextStart($textBuffer, $pos);
                $textBuffer .= $char;
                $pos++;

                continue;
            }

            $nextChar = $text[$pos + 1] ?? '';

            if ($this->parseEscapedOrLineBreak($parent, $text, $pos, $length, $textBuffer)) {
                continue;
            }

            // Delimited inline comment: unlike `%%`, this may begin anywhere
            // in the run and prose resumes after the first `%}`. An opener
            // without a closer is ordinary text. Code and raw spans remain
            // opaque because their backtick handler consumes them as a unit.
            if ($char === '{' && $nextChar === '%') {
                $close = strpos($text, '%}', $pos + 2);
                if ($close !== false) {
                    $this->flushText($parent, $textBuffer);
                    $textBuffer = '';
                    $content = substr($text, $pos + 2, $close - $pos - 2);
                    if (str_starts_with($content, ' ')) {
                        $content = substr($content, 1);
                    }
                    if (str_ends_with($content, ' ')) {
                        $content = substr($content, 0, -1);
                    }
                    $comment = new Comment($content, null, true);
                    $this->placeAt($comment, $pos, $close + 2);
                    $parent->appendChild($comment);
                    $pos = $close + 2;

                    continue;
                }
            }

            if ($char === '#' && $this->isCaptionNumberPlaceholder($text, $pos)) {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                // The `#` placeholder in `^ Figure #: …` IS in the source; only
                // the number it resolves to is not.
                $captionNumber = new CaptionNumber();
                $this->placeAt($captionNumber, $pos, $pos + 1);
                $parent->appendChild($captionNumber);
                $this->captionNumberEmitted = true;
                $pos++;

                continue;
            }

            // Trailing (inline) line comment: `%%` preceded by a space/tab or
            // at the start of the run consumes to the next newline. The
            // preceding whitespace is absorbed; the newline stays (becomes a
            // soft break, so the next line survives). Code spans parse before
            // this on a backtick and consume opaquely; `\%%` is handled by the
            // escape branch above.
            if (
                $char === '%' && $nextChar === '%'
                && ($pos === 0 || $text[$pos - 1] === ' ' || $text[$pos - 1] === "\t")
            ) {
                $nl = strpos($text, "\n", $pos);
                $end = $nl === false ? $length : $nl;
                $content = substr($text, $pos + 2, $end - ($pos + 2));
                // Strip exactly one leading space/tab (the separator between
                // `%%` and the comment text); any further spacing is kept.
                if ($content !== '' && ($content[0] === ' ' || $content[0] === "\t")) {
                    $content = substr($content, 1);
                }
                $textBuffer = rtrim($textBuffer, " \t");
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $comment = new Comment($content);
                $this->placeAt($comment, $pos, $end);
                $parent->appendChild($comment);
                $pos = $end;

                continue;
            }

            // Math: $`...` or $$`...`
            if ($char === '$') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseMath($text, $pos);
                if ($result !== null) {
                    $this->placeAt($result['node'], $pos, $result['pos']);
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
                // Not math, add to buffer
                $this->noteTextStart($textBuffer, $pos);
                $textBuffer .= $char;
                $pos++;

                continue;
            }

            // Inline code (or raw inline `...`{=format})
            if ($char === '`') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseCodeSpan($text, $pos);
                if ($result !== null) {
                    $this->placeAt($result['node'], $pos, $result['pos']);
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Inline extension :type[content] (before :symbol:)
            if ($char === ':') {
                $result = $this->parseInlineExtension($text, $pos);
                if ($result !== null) {
                    $this->flushText($parent, $textBuffer);
                    $textBuffer = '';
                    $this->placeAt($result['node'], $pos, $result['pos']);
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Symbol :name:
            if ($char === ':') {
                $result = $this->parseSymbol($text, $pos);
                if ($result !== null) {
                    $this->flushText($parent, $textBuffer);
                    $textBuffer = '';
                    $this->placeAt($result['node'], $pos, $result['pos']);
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Inline literal: !`...` (PART 9 §27) — mirrors the $`...` math
            // prefix. Tried before the image branch; `!` still binds to `[`
            // for images and stays literal text everywhere else.
            if ($char === '!' && $nextChar === '`') {
                $result = $this->parseLiteralInline($text, $pos);
                if ($result !== null) {
                    $this->flushText($parent, $textBuffer);
                    $textBuffer = '';
                    $this->placeAt($result['node'], $pos, $result['pos']);
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Image: ![alt](src)
            if ($char === '!' && $nextChar === '[') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseImage($text, $pos);
                if ($result !== null) {
                    $this->placeAt($result['node'], $pos, $result['pos']);
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Footnote reference: [^label]
            if ($this->footnoteRecognitionEnabled && $char === '[' && $nextChar === '^') {
                $result = $this->parseFootnoteRef($text, $pos);
                if ($result !== null) {
                    $this->flushText($parent, $textBuffer);
                    $textBuffer = '';
                    $this->placeAt($result['node'], $pos, $result['pos']);
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Link: [text](url) or [text][ref]
            if ($char === '[') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseLink($text, $pos);
                if ($result !== null) {
                    // Check if this is an unclosed link (special handling)
                    if (isset($result['unclosed_link'])) {
                        // Output [ then parse linkText in isolation then output ](
                        $parent->appendChild(new Text('['));
                        $this->parseInlinesAt($parent, $result['link_text'], $pos + 1);
                        $parent->appendChild(new Text(']('));
                        $pos = $result['continue_pos'];

                        continue;
                    }
                    // At this point, result has node/pos (not unclosed_link)
                    $this->placeAt($result['node'], $pos, $result['pos']);
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Heading cross-reference: </#id> (before autolink)
            if ($char === '<' && ($text[$pos + 1] ?? '') === '/' && ($text[$pos + 2] ?? '') === '#') {
                if (preg_match('/\G<\/#([^> \t\r\n]+)>/u', $text, $hm, 0, $pos)) {
                    $this->flushText($parent, $textBuffer);
                    $textBuffer = '';
                    $headingRef = new HeadingRef($hm[1]);
                    $this->placeAt($headingRef, $pos, $pos + strlen($hm[0]));
                    $parent->appendChild($headingRef);
                    $pos += strlen($hm[0]);

                    continue;
                }
            }

            // Autolink: <url> or <email>
            if ($char === '<') {
                $result = $this->parseAutolink($text, $pos);
                if ($result !== null) {
                    $this->flushText($parent, $textBuffer);
                    $textBuffer = '';
                    $this->placeAt($result['node'], $pos, $result['pos']);
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Bold-italic: /*text*/ (check before single-char / italic)
            if ($char === '/' && ($text[$pos + 1] ?? '') === '*') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseBoldItalic($text, $pos);
                if ($result !== null) {
                    $this->placeAt($result['node'], $pos, $result['pos']);
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Italic: /text/
            if ($char === '/') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseDelimited($text, $pos, '/', Emphasis::class);
                if ($result !== null) {
                    $this->placeAt($result['node'], $pos, $result['pos']);
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Underline: _text_
            if ($char === '_') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseDelimited($text, $pos, '_', Underline::class);
                if ($result !== null) {
                    $this->placeAt($result['node'], $pos, $result['pos']);
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Strong: *text*
            if ($char === '*') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseDelimited($text, $pos, '*', Strong::class);
                if ($result !== null) {
                    $this->placeAt($result['node'], $pos, $result['pos']);
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Inline footnote: ^[content]. A `^` anywhere else is literal text
            // (there is no bare superscript), so `^^[x]` is a literal `^` + a note.
            if ($this->footnoteRecognitionEnabled && $char === '^' && $nextChar === '[') {
                $result = $this->parseInlineFootnote($text, $pos);
                if ($result !== null) {
                    $this->flushText($parent, $textBuffer);
                    $textBuffer = '';
                    $this->placeAt($result['node'], $pos, $result['pos']);
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // NO bare superscript/subscript: `^` and `,` are literal outside the
            // braced forms `{^x^}` / `{,x,}` (grammar PART 9 §9 rationale note).

            // Strikethrough: ~text~
            if ($char === '~') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseDelimited($text, $pos, '~', Strike::class);
                if ($result !== null) {
                    $this->placeAt($result['node'], $pos, $result['pos']);
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Highlight: =text=
            if ($char === '=') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseDelimited($text, $pos, '=', Highlight::class);
                if ($result !== null) {
                    $this->placeAt($result['node'], $pos, $result['pos']);
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Special braced syntax: {+insert+} or {-delete-}. Attribute blocks
            // only attach through explicit inline hosts such as [text]{.class},
            // `code`{.class}, or links; a bare word followed by `{...}` is
            // literal text per the inline_span grammar.
            if ($char === '{') {
                // Editorial comment {# ... #} -> styled span. Must precede the
                // attribute check, which would otherwise consume `{# … #}`.
                $comment = $this->parseEditorialComment($text, $pos);
                if ($comment !== null) {
                    $this->flushText($parent, $textBuffer);
                    $textBuffer = '';
                    $this->placeAt($comment['node'], $pos, $comment['pos']);
                    $parent->appendChild($comment['node']);
                    $pos = $comment['pos'];

                    continue;
                }

                // Then try special braced syntax.
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseBracedInline($text, $pos);
                if ($result !== null) {
                    if (isset($result['nodes'])) {
                        // Several nodes from one construct: their individual
                        // extents are not known here, and giving them all the
                        // construct's span would be a position none of them
                        // actually has. They take one from their children if
                        // they have placed ones, or none.
                        foreach ($result['nodes'] as $bracedNode) {
                            $parent->appendChild($bracedNode);
                        }
                    } else {
                        $this->placeAt($result['node'], $pos, $result['pos']);
                        $parent->appendChild($result['node']);
                    }
                    $pos = $result['pos'];

                    continue;
                }
            }

            if ($this->parseSmartTypographyAt($parent, $text, $pos, $textBuffer)) {
                continue;
            }

            $matchResult = $this->tryInlineMatchers($text, $pos);
            if ($matchResult !== null) {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $this->placeAt($matchResult['node'], $pos, $matchResult['end']);
                $parent->appendChild($matchResult['node']);
                $pos = $matchResult['end'];

                continue;
            }

            // Regular character
            $this->noteTextStart($textBuffer, $pos);
            $textBuffer .= $char;
            $pos++;
        }

        $this->flushText($parent, $textBuffer);
        $this->footnoteRecognitionEnabled = $previousFootnoteRecognition;
    }

    protected function parseEscapedOrLineBreak(
        Node $parent,
        string $text,
        int &$pos,
        int $length,
        string &$textBuffer,
    ): bool {
        $char = $text[$pos];
        if ($char === '\\' && $pos + 1 >= $length) {
            $this->appendHardBreak($parent, $textBuffer, $pos, $pos + 1);
            $pos++;

            return true;
        }

        if ($char === '\\' && $pos + 1 < $length) {
            $escaped = $text[$pos + 1];
            if ($escaped === "\n") {
                $this->appendHardBreak($parent, $textBuffer, $pos, $pos + 2);
                $pos += 2;

                return true;
            }
            if ($escaped === "\t" || $escaped === ' ') {
                $lookAhead = $pos + 2;
                while ($lookAhead < $length && ($text[$lookAhead] === ' ' || $text[$lookAhead] === "\t")) {
                    $lookAhead++;
                }
                if ($lookAhead < $length && $text[$lookAhead] === "\n") {
                    $textBuffer = rtrim($textBuffer, " \t");
                    $this->appendHardBreak($parent, $textBuffer, $pos, $lookAhead + 1);
                    $pos = $lookAhead + 1;

                    return true;
                }
                if ($escaped === ' ') {
                    $this->noteTextStart($textBuffer, $pos, rewritten: true, consumed: 2);
                    $textBuffer .= "\u{E000}";
                    $pos += 2;

                    return true;
                }
            }
            if (strpos('!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~', $escaped) !== false) {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $escapedNode = new EscapedText($escaped);
                $this->placeAt($escapedNode, $pos, $pos + 2);
                $parent->appendChild($escapedNode);
                $pos += 2;

                return true;
            }
        }

        if ($char === "\n") {
            $this->flushText($parent, $textBuffer);
            $textBuffer = '';
            $softBreak = new SoftBreak();
            $this->placeAt($softBreak, $pos, $pos + 1);
            $parent->appendChild($softBreak);
            $pos++;

            return true;
        }

        return false;
    }

    protected function appendHardBreak(Node $parent, string &$textBuffer, int $start, int $end): void
    {
        $this->flushText($parent, $textBuffer);
        $textBuffer = '';
        $hardBreak = new HardBreak();
        $this->placeAt($hardBreak, $start, $end);
        $parent->appendChild($hardBreak);
    }

    protected function parseSmartTypographyAt(Node $parent, string $text, int &$pos, string &$textBuffer): bool
    {
        $char = $text[$pos];
        $nextChar = $text[$pos + 1] ?? '';

        if ($char === '"' || $char === "'") {
            $prevConverted = $this->previousConvertedChar($parent, $textBuffer);
            $smartQuote = $this->parseSmartQuote($prevConverted, $text, $pos, $char);

            $this->flushText($parent, $textBuffer);
            $textBuffer = '';
            $quote = new SmartPunctuation($this->smartQuoteKind($smartQuote), $char, $smartQuote);
            $this->placeAt($quote, $pos, $pos + 1);
            $parent->appendChild($quote);
            $pos++;

            return true;
        }

        if ($char === '-' && $nextChar === '-') {
            return $this->parseSmartDashAt($parent, $text, $pos, $textBuffer);
        }

        if ($char === '.' && substr($text, $pos, 3) === '...') {
            $this->flushText($parent, $textBuffer);
            $textBuffer = '';
            $ellipsis = new SmartPunctuation('ellipsis', '...');
            $this->placeAt($ellipsis, $pos, $pos + 3);
            $parent->appendChild($ellipsis);
            $pos += 3;

            return true;
        }

        $symbol = $this->parseSmartSymbol($text, $pos);
        if ($symbol === null) {
            return false;
        }

        $source = substr($text, $pos, $symbol[1]);
        $this->flushText($parent, $textBuffer);
        $textBuffer = '';
        $symbolNode = new SmartPunctuation($this->smartSymbolKind($source), $source);
        $this->placeAt($symbolNode, $pos, $pos + $symbol[1]);
        $parent->appendChild($symbolNode);
        $pos += $symbol[1];

        return true;
    }

    protected function parseSmartDashAt(Node $parent, string $text, int &$pos, string &$textBuffer): bool
    {
        $result = $this->parseSmartDash($text, $pos);
        $glyphs = $result['text'];

        if ($glyphs === '-') {
            $this->noteTextStart($textBuffer, $pos, rewritten: true, consumed: 1);
            $textBuffer .= '-';
            $pos = $result['pos'];

            return true;
        }

        $this->flushText($parent, $textBuffer);
        $textBuffer = '';

        $sourcePos = $pos;
        foreach (preg_split('//u', $glyphs, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $glyph) {
            $kind = $glyph === "\u{2014}" ? 'em_dash' : 'en_dash';
            $width = $kind === 'em_dash' ? 3 : 2;
            $dash = new SmartPunctuation($kind, substr($text, $sourcePos, $width));
            $this->placeAt($dash, $sourcePos, $sourcePos + $width);
            $parent->appendChild($dash);
            $sourcePos += $width;
        }

        $pos = $result['pos'];

        return true;
    }

    protected function isCaptionNumberPlaceholder(string $text, int $pos): bool
    {
        if (!$this->captionContextEnabled || $this->captionNumberEmitted) {
            return false;
        }

        $previous = $text[$pos - 1] ?? '';
        if ($previous !== '' && ($previous === '_' || ctype_alnum($previous))) {
            return false;
        }

        $next = $text[$pos + 1] ?? '';

        return $next === '' || !preg_match('/[A-Za-z]/', $next);
    }

    protected function flushText(Node $parent, string $text): void
    {
        $start = $this->textBufferStart;
        $end = $this->textBufferEnd;
        $rewritten = $this->textBufferRewritten;
        $this->textBufferStart = null;
        $this->textBufferEnd = null;
        $this->textBufferRewritten = false;

        if ($text === '') {
            return;
        }

        // Check if there are any abbreviations to process
        $abbreviations = $this->blockParser->getAbbreviations();
        if ($abbreviations === []) {
            $node = new Text($text);
            if ($rewritten) {
                // A rewritten run cannot be checked by comparing its span's
                // bytes to its text - they differ by construction. It is checked
                // the other way instead: run the source through the same rewrite
                // and require that it PRODUCES the text. That keeps every placed
                // node verified, rather than raising coverage by dropping the
                // check that makes coverage mean anything.
                $node->setPos($this->rewrittenSpan($start, $end, $text));
            } else {
                $this->placeInline($node, $start, $text, $rewritten);
            }
            $parent->appendChild($node);

            return;
        }

        // Process abbreviations in the text
        $this->flushTextWithAbbreviations($parent, $text, $abbreviations, $rewritten ? null : $start);
    }

    /**
     * Flush text while replacing abbreviations with Abbreviation nodes
     *
     * @param \MarkupCarve\Carve\Node\Node $parent
     * @param string $text
     * @param array<string, string> $abbreviations
     * @param int|null $start Where the flushed run began, or null when its text
     *   was rewritten and offsets into it would not line up.
     */
    protected function flushTextWithAbbreviations(
        Node $parent,
        string $text,
        array $abbreviations,
        ?int $start = null,
    ): void {
        // Cache the regex pattern for abbreviations (built once per document)
        if ($this->cachedAbbreviations !== $abbreviations) {
            // Sort abbreviations by length (longest first) to match longer abbreviations first
            // STRINGS, because PHP does not keep them that way. An array key
            // that looks like a decimal integer comes back as an int, so a
            // digit-only abbreviation term - `*[9]: nine`, valid per
            // `abbreviation_term = (letter | digit)+` - handed `strlen()` and
            // `preg_quote()` an int and killed the render with a TypeError
            // (carve#791). A two-line document exited 255 with a stack trace.
            $abbrKeys = array_map(static fn (string|int $key): string => (string)$key, array_keys($abbreviations));
            usort($abbrKeys, fn ($a, $b) => strlen($b) - strlen($a));

            // Build a regex pattern that matches any abbreviation at word boundaries
            // We need to escape special regex characters in abbreviation keys
            $escapedKeys = array_map(fn ($key) => preg_quote($key, '/'), $abbrKeys);
            $this->abbreviationPattern = '/\b(' . implode('|', $escapedKeys) . ')\b/u';
            $this->cachedAbbreviations = $abbreviations;
        }

        // Split text by abbreviation matches, keeping the delimiters
        // Pattern is guaranteed to be set at this point
        /** @var string $pattern */
        $pattern = $this->abbreviationPattern;
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if ($parts === false) {
            // Fallback: just output as plain text
            $parent->appendChild(new Text($text));

            return;
        }

        // Each part is a contiguous run of the text that was buffered, so the
        // offsets follow from the buffer's own start.
        $offset = $start;
        foreach ($parts as $part) {
            if (isset($abbreviations[$part])) {
                // This is an abbreviation match
                $abbr = new Abbreviation($abbreviations[$part]);
                $label = new Text($part);
                $this->placeInline($label, $offset, $part, false);
                $this->placeInline($abbr, $offset, $part, false);
                $abbr->appendChild($label);
                $parent->appendChild($abbr);
            } else {
                // Regular text
                $node = new Text($part);
                $this->placeInline($node, $offset, $part, false);
                $parent->appendChild($node);
            }
            if ($offset !== null) {
                $offset += strlen($part);
            }
        }
    }

    /**
     * @return array{node: \MarkupCarve\Carve\Node\Node, end: int}|null
     */
    protected function tryInlineMatchers(string $text, int $pos): ?array
    {
        if ($this->inlineMatchers === []) {
            return null;
        }

        $char = $text[$pos];
        $ctx = $this->inlineMatcherContext ??= new MatcherContext($this->blockParser, $this);
        foreach ($this->compiledInlineMatchers() as $entry) {
            // Skip a matcher whose trigger set does not contain the current
            // byte: its anchored preg_match would fail here anyway. A null set
            // means "runs everywhere".
            if ($entry['first'] !== null && !isset($entry['first'][$char])) {
                continue;
            }
            $result = ($entry['matcher'])($text, $pos, $ctx);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Build (once) the priority-ordered matcher list paired with each pattern's
     * required first literal byte, for first-char gating in tryInlineMatchers().
     *
     * @return array<array{matcher: \Closure, first: array<string, true>|null}>
     */
    protected function compiledInlineMatchers(): array
    {
        if ($this->compiledInlineMatchers !== null) {
            return $this->compiledInlineMatchers;
        }

        $entries = $this->inlineMatchers;
        usort($entries, static function (array $a, array $b): int {
            return $b['priority'] <=> $a['priority'] ?: $a['seq'] <=> $b['seq'];
        });

        return $this->compiledInlineMatchers = array_map(
            fn (array $entry): array => [
                'matcher' => $entry['matcher'],
                'first' => $this->matcherFirstBytes($entry['pattern'], $entry['triggerChars']),
            ],
            $entries,
        );
    }

    /**
     * Resolve the trigger-byte set for a registered matcher: explicit
     * `triggerChars` (each byte its own trigger) take precedence; otherwise it
     * is derived from the regex pattern. Null = run everywhere.
     *
     * @return array<string, true>|null
     */
    protected function matcherFirstBytes(?string $pattern, ?string $triggerChars): ?array
    {
        if ($triggerChars !== null && $triggerChars !== '') {
            $set = [];
            $len = strlen($triggerChars);
            for ($i = 0; $i < $len; $i++) {
                $set[$triggerChars[$i]] = true;
            }

            return $set;
        }

        return $this->patternFirstBytes($pattern);
    }

    /**
     * The SET of literal ASCII bytes a delimited regex pattern can legally start
     * with (a map byte => true), or null when the set cannot be safely bounded
     * (regex metacharacter, multibyte, anchor, wildcard class, ...) -- in which
     * case the matcher must run at every position.
     *
     * CORRECTNESS: the returned set is a pure gating optimization, so it MUST be
     * a complete superset of every byte that can begin a match. When in doubt,
     * return null (run everywhere). Handles: a leading escaped literal (`\[`),
     * an alternation/group of literals (`(http|https|ftp)`), a positive char
     * class (`[abc]`), a plain literal, and the `i` (case-insensitive) flag
     * (adds both cases of each letter).
     *
     * @return array<string, true>|null
     */
    protected function patternFirstBytes(?string $pattern): ?array
    {
        if ($pattern === null || strlen($pattern) < 2) {
            return null;
        }

        $delim = $pattern[0];
        $len = strlen($pattern);

        // Locate the closing delimiter (last occurrence of $delim) so the flags
        // segment after it can be inspected. The body is everything between the
        // opening and closing delimiter.
        $closeDelim = strrpos($pattern, $delim);
        if ($closeDelim === false || $closeDelim < 1) {
            return null;
        }
        $flags = substr($pattern, $closeDelim + 1);
        // Extended/free-spacing mode (`x`) makes literal whitespace and `#`
        // comments insignificant, so the first BYTE of the body is not the first
        // token consumed. Deriving a trigger from it would be unsound -> run
        // everywhere.
        if (str_contains($flags, 'x')) {
            return null;
        }
        $caseInsensitive = str_contains($flags, 'i');
        // Unicode case-insensitive (`iu`) applies full Unicode case folding, so a
        // letter trigger can also match a multibyte fold (e.g. `k` matches the
        // Kelvin sign U+212A). ASCII-only case expansion is not a complete
        // superset then -> run everywhere.
        if ($caseInsensitive && str_contains($flags, 'u')) {
            return null;
        }

        $i = 1;

        // Skip a leading lookbehind assertion (zero-width: consumes no input, so
        // the real trigger follows it). Safe -- the anchored matcher still
        // requires the trigger byte at the position regardless of the lookbehind.
        if (substr($pattern, $i, 4) === '(?<!' || substr($pattern, $i, 4) === '(?<=') {
            $depth = 0;
            while ($i < $len) {
                $ch = $pattern[$i];
                if ($ch === '\\') {
                    $i += 2;

                    continue;
                }
                if ($ch === '[') {
                    $i++;
                    while ($i < $len && $pattern[$i] !== ']') {
                        $i += $pattern[$i] === '\\' ? 2 : 1;
                    }
                    $i++;

                    continue;
                }
                if ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $i++;

                        break;
                    }
                }
                $i++;
            }
        }

        $bytes = $this->firstBytesOf($pattern, $i, $closeDelim);
        if ($bytes === null) {
            return null;
        }

        if (!$caseInsensitive) {
            return $bytes;
        }

        // Case-insensitive: every letter trigger can appear in either case.
        $expanded = [];
        foreach (array_keys($bytes) as $b) {
            $expanded[$b] = true;
            if (ctype_alpha($b)) {
                $expanded[strtoupper($b)] = true;
                $expanded[strtolower($b)] = true;
            }
        }

        return $expanded;
    }

    /**
     * Compute the trigger-byte set of the regex SEQUENCE spanning [$i, $end).
     * The sequence may itself be a top-level alternation `A|B|C` -- each
     * alternative's first bytes are unioned. Returns null if any alternative is
     * indeterminate (so the matcher must run everywhere).
     *
     * @return array<string, true>|null
     */
    protected function firstBytesOf(string $pattern, int $i, int $end): ?array
    {
        if ($i >= $end) {
            return null;
        }

        // Split on top-level `|` (not inside groups or char classes) and union
        // the first bytes of every alternative.
        $bytes = [];
        $altStart = $i;
        $depth = 0;
        $j = $i;
        while ($j <= $end) {
            $ch = $j < $end ? $pattern[$j] : '';
            $atEnd = $j === $end;
            if (!$atEnd && $ch === '\\') {
                $j += 2;

                continue;
            }
            if (!$atEnd && $ch === '[') {
                $j++;
                while ($j < $end && $pattern[$j] !== ']') {
                    $j += $pattern[$j] === '\\' ? 2 : 1;
                }
                $j++;

                continue;
            }
            if (!$atEnd && $ch === '(') {
                $depth++;
            } elseif (!$atEnd && $ch === ')') {
                $depth--;
            }

            if ($atEnd || ($ch === '|' && $depth === 0)) {
                $altBytes = $this->branchFirstBytes($pattern, $altStart, $j);
                if ($altBytes === null) {
                    return null;
                }
                foreach (array_keys($altBytes) as $b) {
                    $bytes[$b] = true;
                }
                $altStart = $j + 1;
            }
            $j++;
        }

        return $bytes === [] ? null : $bytes;
    }

    /**
     * First-byte set of a SINGLE alternative (no top-level `|`) spanning
     * [$i, $end): the first token's bytes, unless a quantifier makes that token
     * optional (then the following token could also start the match, so the set
     * is indeterminate -> null).
     *
     * @return array<string, true>|null
     */
    protected function branchFirstBytes(string $pattern, int $i, int $end): ?array
    {
        $c = $pattern[$i] ?? '';
        if ($c === '' || $i >= $end) {
            return null;
        }

        // Escaped first char. Only a literal punctuation escape (`\[`, `\.`,
        // `\$`) has a determinate first byte; a class shorthand (`\d`, `\w`,
        // `\b`, ...) does not.
        if ($c === '\\') {
            $next = $pattern[$i + 1] ?? '';
            if ($next === '' || ctype_alnum($next) || ord($next) > 127) {
                return null;
            }
            if ($this->isOptionalQuantifier($pattern, $i + 2)) {
                return null;
            }

            return [$next => true];
        }

        // Group `(...)`: the union of its branches' first bytes (handles its own
        // optional-quantifier check).
        if ($c === '(') {
            return $this->groupFirstBytes($pattern, $i, $end);
        }

        // Positive char class `[abc]` / enumerable range (handles its own
        // optional-quantifier check). A negated class, shorthand, or multibyte
        // bound -> null.
        if ($c === '[') {
            return $this->charClassFirstBytes($pattern, $i, $end);
        }

        // Any other metacharacter (anchor ^, wildcard ., $, quantifiers, ...)
        // cannot be reduced to a literal trigger set.
        if (in_array($c, ['^', '.', '$', '|', '?', '*', '+', '{', ')', ']', '}'], true)) {
            return null;
        }

        // Multibyte / non-ASCII first byte.
        if (ord($c) > 127) {
            return null;
        }

        // Plain literal byte. A quantifier directly after it that permits zero
        // repetitions (`a?`, `a*`, `a{0,n}`, `a{,n}`) makes it optional, so the
        // following token could also start the match -> indeterminate.
        if ($this->isOptionalQuantifier($pattern, $i + 1)) {
            return null;
        }

        return [$c => true];
    }

    /**
     * Whether the quantifier at offset $i (if any) makes the immediately
     * preceding token optional, i.e. permits zero repetitions: `?`, `*`, or a
     * counted form whose minimum is zero (`{0}`, `{0,n}`, `{,n}`). A non-zero
     * minimum (`{2}`, `{1,3}`) keeps the token mandatory. An unparseable `{...}`
     * is treated conservatively as optional (returns true).
     */
    protected function isOptionalQuantifier(string $pattern, int $i): bool
    {
        $ch = $pattern[$i] ?? '';
        if ($ch === '?' || $ch === '*') {
            return true;
        }
        if ($ch !== '{') {
            return false;
        }

        $close = strpos($pattern, '}', $i);
        if ($close === false) {
            // Not a quantifier ({ is a literal here) -- token stays mandatory.
            return false;
        }
        $body = substr($pattern, $i + 1, $close - $i - 1);
        // {,n} -> minimum 0; {0...} -> minimum 0. Anything else with a nonzero
        // leading integer keeps the token mandatory.
        if ($body === '' || $body[0] === ',') {
            return true;
        }
        if (!ctype_digit($body[0])) {
            // Not a real counted quantifier; treat `{` as literal -> mandatory.
            return false;
        }
        $min = (int)$body;

        return $min === 0;
    }

    /**
     * First-byte set of a leading group `(...)` -- the union of every
     * alternative's first bytes. Null if the group is non-capturing-but-special
     * (lookahead/lookbehind), unbalanced, or any alternative is indeterminate.
     *
     * @return array<string, true>|null
     */
    protected function groupFirstBytes(string $pattern, int $start, int $end): ?array
    {
        // Reject lookarounds and other (?...) extensions: their first consuming
        // byte is not the byte after `(`.
        if (($pattern[$start + 1] ?? '') === '?') {
            return null;
        }

        // Find the matching close paren (respecting escapes and nested classes),
        // bounded by $end.
        $depth = 0;
        $groupEnd = null;
        $i = $start;
        while ($i < $end) {
            $ch = $pattern[$i];
            if ($ch === '\\') {
                $i += 2;

                continue;
            }
            if ($ch === '[') {
                $i++;
                while ($i < $end && $pattern[$i] !== ']') {
                    $i += $pattern[$i] === '\\' ? 2 : 1;
                }
                $i++;

                continue;
            }
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    $groupEnd = $i;

                    break;
                }
            }
            $i++;
        }
        if ($groupEnd === null) {
            return null;
        }

        // A quantifier making the whole group optional means the trigger could
        // be whatever follows the group -> indeterminate.
        if ($this->isOptionalQuantifier($pattern, $groupEnd + 1)) {
            return null;
        }

        // The group body is itself a (possibly alternated) sequence; its first
        // bytes are computed by the alternation-aware firstBytesOf().
        return $this->firstBytesOf($pattern, $start + 1, $groupEnd);
    }

    /**
     * First-byte set of a leading positive char class `[abc]` or `[a-z0-9._-]`.
     *
     * Enumerable ASCII ranges (`a-z`, `A-Z`, `0-9`, ...) are expanded to their
     * member bytes -- still a complete, correct superset, and the dominant gain
     * for matchers whose pattern starts with a bounded class (bare-email
     * autolink starts with `[a-zA-Z0-9._%+-]`). Returns null for a negated class
     * `[^...]`, a shorthand escape (`\d`, `\w`) anywhere inside, a multibyte
     * range bound, an empty class, or an unterminated class -- all too broad or
     * indeterminate to enumerate safely.
     *
     * @return array<string, true>|null
     */
    protected function charClassFirstBytes(string $pattern, int $start, int $end): ?array
    {
        $i = $start + 1;
        if (($pattern[$i] ?? '') === '^') {
            return null;
        }

        $bytes = [];
        while ($i < $end) {
            $ch = $pattern[$i];
            if ($ch === ']') {
                // Class closed. A quantifier making it optional -> indeterminate.
                if ($this->isOptionalQuantifier($pattern, $i + 1)) {
                    return null;
                }

                return $bytes === [] ? null : $bytes;
            }
            // A shorthand escape (`\d`, `\w`, `\s`, ...) inside the class is too
            // broad to enumerate.
            if ($ch === '\\') {
                return null;
            }
            // A `[` inside the class begins a POSIX class `[:alpha:]`, an
            // equivalence class `[=e=]`, or a collating element `[.ch.]` -- all
            // far broader than the literal bytes they are spelled with, and the
            // inner `]` would be misread as the class terminator. Indeterminate.
            if ($ch === '[') {
                return null;
            }
            if (ord($ch) > 127) {
                return null;
            }

            // Range `X-Y`: enumerate the inclusive ASCII span. A `-` at the very
            // start/end of the class or with a non-literal bound is a literal
            // hyphen, handled by the literal branch below.
            $next = $pattern[$i + 1] ?? '';
            $after2 = $pattern[$i + 2] ?? '';
            if ($next === '-' && $after2 !== '' && $after2 !== ']' && $after2 !== '\\' && ord($after2) <= 127) {
                $from = ord($ch);
                $to = ord($after2);
                if ($to < $from) {
                    // Malformed range -- do not guess.
                    return null;
                }
                for ($b = $from; $b <= $to; $b++) {
                    $bytes[chr($b)] = true;
                }
                $i += 3;

                continue;
            }

            $bytes[$ch] = true;
            $i++;
        }

        // Unterminated class.
        return null;
    }

    /**
     * Bytes that can begin an inline construct in the main scan, or null when
     * the plain-char fast path must be disabled (a registered matcher has no
     * determinable first byte and so must run at every position). The static
     * set lists every literal handled in parseInlines() plus the first bytes of
     * the smart-symbol map; matcher first bytes are added on top.
     *
     * @return array<string, true>|null
     */
    protected function significantInlineBytes(): ?array
    {
        if ($this->inlineSignificantComputed) {
            return $this->inlineSignificantBytes;
        }
        $this->inlineSignificantComputed = true;

        $sig = [];
        // Char handlers in parseInlines + parseSmartSymbol/parseSmartDash heads.
        $base = [
            '\\', "\n", '%', '$', '`', ':', '!', '[', '<', '/',
            '_', '*',
            '^', '~', ',', '=', '{', '"', "'", '-', '.', '(',
            '>', '+',
        ];
        foreach ($base as $b) {
            $sig[$b] = true;
        }

        foreach ($this->compiledInlineMatchers() as $entry) {
            if ($entry['first'] === null) {
                // A matcher with no determinable trigger set runs at every
                // position, so the document-wide fast-skip cannot apply.
                return $this->inlineSignificantBytes = null;
            }
            foreach (array_keys($entry['first']) as $b) {
                $sig[$b] = true;
            }
        }

        return $this->inlineSignificantBytes = $sig;
    }

    /**
     * The closed-verbatim-span single-space strip: one leading and one trailing
     * space are removed when the content BOTH begins and ends with a space - but
     * NOT when it consists entirely of spaces. The all-space guard matches the
     * executable spec's `codeText()` and the CommonMark rule it derives from
     * ("...but does not consist entirely of space characters"): all-space content
     * has no representable stripped form, and padding it on output grew the span
     * by two spaces on every fmt pass.
     *
     * Shared by the code span, math and inline literal scanners so the three
     * cannot drift apart - that drift is what produced this bug.
     */
    protected function stripVerbatimPadding(string $content): string
    {
        if (strlen($content) < 2 || $content[0] !== ' ' || $content[strlen($content) - 1] !== ' ') {
            return $content;
        }
        if (strspn($content, ' ') === strlen($content)) {
            return $content;
        }

        return substr($content, 1, -1);
    }

    /**
     * @return array{node: \MarkupCarve\Carve\Node\Inline\Code|\MarkupCarve\Carve\Node\Inline\RawInline, pos: int}|null
     */
    protected function parseCodeSpan(string $text, int $pos): ?array
    {
        // Count opening backticks
        $openBackticks = 0;
        $length = strlen($text);

        while ($pos + $openBackticks < $length && $text[$pos + $openBackticks] === '`') {
            $openBackticks++;
        }

        $contentStart = $pos + $openBackticks;
        $searchPos = $contentStart;

        // Find matching closing backticks
        // Handle edge case: backticks at end of text with no content after
        if ($searchPos >= $length) {
            return [
                'node' => new Code(''),
                'pos' => $length,
            ];
        }

        while ($searchPos < $length) {
            $closePos = strpos($text, str_repeat('`', $openBackticks), $searchPos);
            if ($closePos === false) {
                // No closing backticks found - in djot, unclosed code spans
                // extend to end of paragraph content
                $remaining = substr($text, $contentStart);

                return [
                    'node' => new Code($remaining),
                    'pos' => $length,
                ];
            }

            // Make sure we have exactly the right number of backticks (not more)
            // Check both before and after the match
            $afterClose = $closePos + $openBackticks;
            $beforeClose = $closePos > 0 ? $text[$closePos - 1] : '';
            $afterChar = $afterClose < $length ? $text[$afterClose] : '';

            // Skip if this is inside a longer run of backticks
            if ($beforeClose === '`' || $afterChar === '`') {
                // Move past this backtick run to find the next potential match
                while ($searchPos < $length && $text[$searchPos] === '`') {
                    $searchPos++;
                }
                if ($searchPos < $length) {
                    $searchPos++;
                }

                continue;
            }

            // Found exact match
            $content = substr($text, $contentStart, $closePos - $contentStart);

            $content = $this->stripVerbatimPadding($content);

            // Check for raw inline format: `...`{=format}
            // Format must be ONLY {=format} with no other attributes
            $endPos = $afterClose;
            $hasRawInlineAttempt = $afterClose < $length && $text[$afterClose] === '{'
                && $afterClose + 1 < $length && $text[$afterClose + 1] === '=';
            if ($hasRawInlineAttempt) {
                $formatEnd = strpos($text, '}', $afterClose);
                if ($formatEnd !== false) {
                    $format = substr($text, $afterClose + 2, $formatEnd - $afterClose - 2);
                    // Only accept pure format (alphanumeric/hyphen), reject if mixed with other attributes
                    if (preg_match('/^[a-zA-Z0-9-]+$/', $format)) {
                        $endPos = $formatEnd + 1;

                        return [
                            'node' => new RawInline($content, $format),
                            'pos' => $endPos,
                        ];
                    }
                    // Mixed attributes like {=html #id} - treat attribute block as literal text
                    // Don't parse as trailing attributes either
                }
            }

            $code = new Code($content);

            // Check for trailing attributes: `code`{.class}{.more}
            // But NOT if there was a {= pattern (failed raw inline attempt should be literal)
            if (!$hasRawInlineAttempt && $endPos < $length && $text[$endPos] === '{') {
                $endPos = $this->applyConsecutiveAttributes($code, $text, $endPos);
            }

            return [
                'node' => $code,
                'pos' => $endPos,
            ];
        }

        // The loop exhausted every candidate closer as part of a LONGER
        // backtick run, so there is no equal-length closer: an unclosed run
        // extends to the end of the block (grammar §712), matching carve-js /
        // carve-rs. Previously this returned null, making the opener literal and
        // emitting a spurious empty <code> (`` `a`` `` -> `` `a<code></code> ``).
        $remaining = substr($text, $contentStart);

        return [
            'node' => new Code($remaining),
            'pos' => $length,
        ];
    }

    /**
     * The `link_text` slot carries the run between the brackets exactly as
     * `findBalancedBracketEnd` closed it. `parseImage` reads its alt text from
     * that slot rather than rescanning, so an image's alt closes where a link's
     * text closes by construction (markup-carve/carve#1206).
     *
     * @return array{node: \MarkupCarve\Carve\Node\Inline\Link|\MarkupCarve\Carve\Node\Inline\Span|\MarkupCarve\Carve\Node\Inline\Text, pos: int, link_text: string}|array{unclosed_link: true, link_text: string, continue_pos: int}|null
     */
    protected function parseLink(string $text, int $pos): ?array
    {
        $length = strlen($text);

        // A link/image needs a closing `]`. Without this guard, every `[` runs
        // the char-by-char depth scan below to end-of-text, so an unbalanced run
        // like `[[[[...` is O(n^2). strpos is a C-level memchr that short-circuits
        // when no `]` follows.
        if (strpos($text, ']', $pos + 1) === false) {
            return null;
        }

        // A link, reference, or inline span can only form when the matched `]` is
        // directly followed by `(`, `[`, or `{` (there is no bare shortcut-ref
        // form). If the text contains none of `](`, `][`, `]{`, nothing can start
        // here, so skip the bracket-depth scan below -- otherwise a deeply nested
        // run like `[[[[x]]]]` is O(n^2). The presence check is memoized per text
        // (the same string instance is compared pointer-cheap), so each call is
        // O(1) for trigger-free text.
        if ($text !== $this->linkTriggerText) {
            $this->linkTriggerText = $text;
            $this->linkTriggerPresent = strpos($text, '](') !== false
                || strpos($text, '][') !== false
                || strpos($text, ']{') !== false;
        }
        if (!$this->linkTriggerPresent) {
            return null;
        }

        $textEnd = $this->findBalancedBracketEnd($text, $pos);
        if ($textEnd === null) {
            return null;
        }

        $linkText = substr($text, $pos + 1, $textEnd - $pos - 1);
        $afterBracket = $textEnd + 1;

        // Inline link: [text](url) or [text](url){.class}
        if ($afterBracket < $length && $text[$afterBracket] === '(') {
            $urlStart = $afterBracket + 1;
            $urlEnd = $urlStart;

            // Short-circuit when no `)` lies at or after the destination start:
            // the char scan below could only run to end-of-text and fall through
            // to the unclosed-link result. Skipping it keeps a `)`-less run like
            // `[a](` repeated at O(n) instead of O(n^2). The last-paren position
            // is memoized per text (strrpos runs once), so this guard is O(1).
            $lastCloseParen = $this->lastCloseParenPos($text);
            if ($lastCloseParen === false || $urlStart > $lastCloseParen) {
                return [
                    'unclosed_link' => true,
                    'link_text' => $linkText,
                    'continue_pos' => $urlStart, // Position after (
                ];
            }

            // A destination's parentheses BALANCE: the scan ends at the first
            // `)` with no opener left to pair with, so a URL carrying a
            // parenthesis (Wikipedia, MDN) is written plainly. Djot and
            // CommonMark both balance the same way. An escaped character never
            // opens or closes a level.
            $depth = 0;
            while ($urlEnd < $length) {
                $char = $text[$urlEnd];
                if ($char === '\\' && $urlEnd + 1 < $length) {
                    $urlEnd += 2;

                    continue;
                }
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                }
                $urlEnd++;
            }

            if ($urlEnd < $length && $text[$urlEnd] === ')') {
                $raw = substr($text, $urlStart, $urlEnd - $urlStart);
                if ($raw !== trim($raw)) {
                    return null;
                }

                // Optional title after the destination, separated by
                // whitespace (a soft line break counts): "title",
                // 'title', or (title). A double/single quote delimiter may
                // be backslash-escaped INSIDE the title and is kept as a
                // literal quote (CommonMark-style; grammar.ebnf link_title,
                // decision D). This escape applies to inline-link titles
                // only -- reference-definition titles deliberately do not
                // honor it (see ref-def parsing / grammar known divergence).
                // EXACTLY ONE SPACE, not a run. `link_title = space, ('"' …)`
                // spells the slot `space`, and PART 7's cardinality paragraph
                // holds the production right against the four artifacts that
                // accepted a run (carve#912): a second space at this slot is
                // not padding. The slot does not match, the quoted run is left
                // unconsumed, the destination then carries a space and fails
                // its own `unicode_url_char` test below - so `[t](/u  "T")` is
                // not a link at all and every character survives as text.
                //
                // `([\s\S]*?)` is lazy and would otherwise absorb the extra
                // space itself, which is why narrowing ` +` to ` ` is enough
                // here only in company with the destination's whitespace
                // check: the run has to end up INSIDE the destination for the
                // link to fail.
                $title = null;
                if (
                    preg_match('/^([\s\S]*?) "((?:\\\\"|[^"])*)"$/', $raw, $tm)
                    || preg_match('/^([\s\S]*?) \'((?:\\\\\'|[^\'])*)\'$/', $raw, $tm)
                    || preg_match('/^([\s\S]*?) \(([^()]*)\)$/', $raw, $tm)
                ) {
                    $raw = $tm[1];
                    // Unescape any backslash + ASCII-punctuation inside the
                    // title (`\"` -> `"`, `\.` -> `.`); a backslash before a
                    // non-punctuation char is kept. Matches the canonical
                    // carve-js unescapeAttrValue / carve-rs unescape_title.
                    $title = AttributeParser::processEscapes($tm[2]);
                }

                // The destination (what remains after splitting off any
                // title) ends at the first whitespace; a newline counts as
                // whitespace too, so `[t](url` / `more)` is NOT a link and
                // stays literal (grammar.ebnf link_destination, decision B).
                $url = $raw;
                // UNICODE whitespace, not just ASCII: `unicode_url_char` is
                // "any non-whitespace, non-ASCII Unicode character", with no
                // qualifier, so a narrow no-break space is not a destination
                // character either. `\s` is byte-based and let one through, so
                // `[x](<U+202F>https://e.com)` linked with the invisible
                // character sitting in the href (carve#404).
                if (
                    $url === ''
                    || preg_match('/[\p{Z}\x{0009}-\x{000D}\x{0085}]/u', $url)
                    || (str_starts_with($url, '<') && str_ends_with($url, '>'))
                ) {
                    return null;
                }

                // An escaped parenthesis, and an escaped backslash, are the only
                // escapes a destination has -- they carry the unbalanced case,
                // which balancing alone cannot express. A backslash before
                // anything else is an ordinary URL character (grammar
                // url_char) kept verbatim, matching carve-js / carve-rs, so
                // `[t](a\b)` still links to `a\b`.
                $url = strtr($url, ['\\(' => '(', '\\)' => ')', '\\\\' => '\\']);

                $link = new Link($url, $title);
                $this->parseInlinesAt($link, $linkText, $pos + 1);

                // Track anchor links for validation
                if (preg_match('/^#(.+)$/', $url, $anchorMatch)) {
                    $this->blockParser->trackAnchorLink($anchorMatch[1], $this->currentLine, $pos + 1);
                }

                $endPos = $urlEnd + 1;

                // Check for attributes after link: [text](url){.class}{.more}
                if ($endPos < $length && $text[$endPos] === '{') {
                    $endPos = $this->applyConsecutiveAttributes($link, $text, $endPos);
                }

                return [
                    'node' => $link,
                    'pos' => $endPos,
                    'link_text' => $linkText,
                ];
            }

            // Unclosed parenthesis - not a valid link
            // Parse [text] as isolated inline content, then continue from after (
            // This prevents emphasis from crossing the [text]( boundary
            return [
                'unclosed_link' => true,
                'link_text' => $linkText,
                'continue_pos' => $urlStart, // Position after (
            ];
        }

        // Reference link: [text][ref] or [text][]{.class}
        if ($afterBracket < $length && $text[$afterBracket] === '[') {
            $refEnd = strpos($text, ']', $afterBracket + 1);
            if ($refEnd !== false) {
                $ref = substr($text, $afterBracket + 1, $refEnd - $afterBracket - 1);

                // For empty reference [text][], the label IS the bracket text,
                // whitespace-collapsed - the same normalization the explicit
                // form below uses. Definitions are stored under the label the
                // author wrote, so anything else cannot find them.
                //
                // This used to STRIP inline formatting characters from the
                // label, which inverted the whole rule: a definition carrying
                // any of them could not be reached by the label that defined
                // it, while a plain definition WAS reached by a decorated
                // label that never named it (carve-php#768).
                // EXACT on both forms. A collapsed `[text][]` takes the link
                // text as its label and an explicit `[text][ref]` takes the ref,
                // and neither is folded or trimmed - §6 and PART 9R R1 say
                // "case-sensitive, no whitespace folding". carve-js and carve-rs
                // agree on both forms; this engine folded both.
                if ($ref === '') {
                    $ref = $linkText;
                }

                // Store original bracket content before normalization
                $originalRefBracket = substr($text, $afterBracket + 1, $refEnd - $afterBracket - 1);

                // THE LABEL IS PARSED ONCE, HERE. Both branches below need the
                // bracket text as inline nodes, and the heading-index retry
                // needs the plain text those nodes render to - deriving either
                // one twice is how the label acquires two spellings.
                $label = new Span();
                $this->parseInlinesAt($label, $linkText, $pos + 1);

                // Set only where the heading retry below resolves the
                // reference, which is the one path where the label the author
                // wrote and the string PART 12 §3a publishes differ.
                $derivedRef = null;

                $refDef = $originalRefBracket === ''
                    ? $this->blockParser->getCollapsedReference($ref)
                    : $this->blockParser->getReference($ref);
                if ($refDef === null && $originalRefBracket === '') {
                    // A HEADING-derived definition (PART 11 R1) is keyed by
                    // the heading's RENDERED PLAIN TEXT, so a label carrying
                    // inline markup cannot match it as written: a heading
                    // holding a code span registers the span's content, not its
                    // backticks. Retry once with the label reduced to the same
                    // string kind, and accept the result only when it came from
                    // a heading - an authored definition line is matched by the
                    // label the author wrote, nothing else.
                    //
                    // R1 SAYS "THE SAME STRING KIND THE HEADING SIDE ALREADY
                    // ENTERS AS", so the reduction is the heading side's own
                    // extraction over the PARSED label rather than a character
                    // class over its source. A character class answers only for
                    // the delimiters someone remembered to list: it left `/em/`
                    // (Carve's emphasis is the slash), `\_` (the escape
                    // survives as a backslash the heading text does not carry),
                    // `[x](/y)` (the destination stays behind) and a smart
                    // apostrophe (the heading holds the glyph, the label the
                    // typed `'`) all unmatchable, so no heading containing them
                    // was reachable by its collapsed spelling at all
                    // (markup-carve/carve#1011). Running the extraction instead
                    // needs no list: whatever the heading contributes, the
                    // label contributes too.
                    $plain = $this->blockParser->headingIndexKey($label);
                    if ($plain !== $ref && $plain !== '') {
                        $headingDef = $this->blockParser->getCollapsedReference($plain);
                        if ($headingDef !== null && $headingDef->fromHeading) {
                            $refDef = $headingDef;
                            $ref = $plain;
                            // WHAT RESOLVED IT AND WHAT IT PUBLISHES ARE TWO
                            // STRINGS. `$ref` stays the index key, because that
                            // is what the definition was registered under and
                            // what markReferenceUsed() has to find it by. PART
                            // 12 §3a's `ref` is the DERIVED text - the same
                            // extraction without R1's match-time trim and
                            // collapse - so an authored `[My  Heading][]` keeps
                            // its double space the way carve-js and carve-rs
                            // keep it (markup-carve/carve#1023).
                            $derivedRef = $this->blockParser->headingIndexLabel($label);
                        }
                    }
                }
                if ($refDef !== null) {
                    // Track reference usage for validation
                    $this->blockParser->markReferenceUsed($ref, $this->currentLine);

                    $link = new Link($refDef->url, $refDef->title);
                    // PART 12 §3a, A RESOLVED REFERENCE KEEPS ITS DESTINATION:
                    // the authored construct survives BESIDE the resolution
                    // result. The label is the one the author wrote - the same
                    // spelling the unresolved branch below stores - rather than
                    // `''` for the collapsed form, which lost it (carve#597).
                    $link->setReferenceLabel(
                        $originalRefBracket === '' ? ($derivedRef ?? $ref) : $originalRefBracket,
                    );
                    // Whether the definition was DERIVED from a heading
                    // (PART 11 R1) rather than written as a `[label]: url`
                    // line. Only the canonical writer reads it, and only to
                    // decide whether the authored reference is reproducible:
                    // a heading has no definition line, so `[text][]` is the
                    // only record of it (carve#478).
                    $link->setFromHeadingReference($refDef->fromHeading);
                    $link->setChildren($label->getChildren());

                    // Track anchor links for validation
                    if (preg_match('/^#(.+)$/', $refDef->url, $anchorMatch)) {
                        $this->blockParser->trackAnchorLink($anchorMatch[1], $this->currentLine, $pos + 1);
                    }

                    // Apply attributes from reference definition first
                    foreach ($refDef->attributes as $key => $value) {
                        if ($key === 'class') {
                            $link->addClass((string)$value);
                        } else {
                            $link->setAttribute($key, (string)$value);
                        }
                    }

                    $endPos = $refEnd + 1;

                    // Check for attributes after reference link (override definition attrs)
                    if ($endPos < $length && $text[$endPos] === '{') {
                        $endPos = $this->applyConsecutiveAttributes($link, $text, $endPos);
                    }

                    // The authored source, as on the unresolved branch: §3a asks
                    // for `ref` AND `rawRef` beside the destination, and without
                    // this a resolved reference published half the pair.
                    $link->setRawReferenceLabel(substr($text, $pos, $endPos - $pos));

                    return [
                        'node' => $link,
                        'pos' => $endPos,
                        'link_text' => $linkText,
                    ];
                }

                // Reference not found. It is STILL a link node (PART 12 §3a):
                // the tree records what the author wrote, and reverting to
                // literal source discarded the fact that a reference was
                // written at all. It renders as its source - every writer emits
                // the raw reference below - so the output is unchanged.
                $link = new Link('', null);
                // The AUTHORED label, as carve-js publishes it: the bracket
                // content verbatim, or the label derived from the link text for
                // the collapsed form. The RESOLVED branch above stores `''` for
                // the collapsed form instead, which the canonical writer reads;
                // that spelling is markup-carve/carve#524.
                $link->setReferenceLabel($originalRefBracket === '' ? $ref : $originalRefBracket);
                $link->setChildren($label->getChildren());

                // Either form may still land on a heading - collapsed
                // `[text][]` case-insensitively, explicit `[text][Label]`
                // exactly - and the heading index is built from the parsed
                // tree, so it does not exist yet (R1; carve-php#572). Flag it
                // so the parser knows a second pass is worth running.
                $this->blockParser->markCollapsedReferenceUnresolved();
                [$warnLine, $warnColumn] = $this->lineAndColumnAt($pos);
                $this->blockParser->addUndefinedReferenceWarning($ref, $warnLine, $warnColumn);
                $endPos = $refEnd + 1;
                if ($endPos < $length && $text[$endPos] === '{') {
                    $endPos = $this->applyConsecutiveAttributes($link, $text, $endPos);
                }
                $link->setRawReferenceLabel(substr($text, $pos, $endPos - $pos));

                return [
                    'node' => $link,
                    'pos' => $endPos,
                    'link_text' => $linkText,
                ];
            }
        }

        // Inline span [text]{attrs} (PART 9 §14). A bracketed run forms a
        // <span> only when the directly-abutting block is a VALID attribute
        // block: one that yields an attribute, OR an empty/whitespace-only
        // block. carve-php materializes the empty case as a bare <span> so a
        // default-attribute extension can target it ([x]{}, [x]{ }). A block
        // carrying unrecognized content ({???}, {=y=}, {"{y}"}) is not an
        // attribute block: we fall through and the brackets and block render
        // literally (the inner bracket content is still inline-parsed, so
        // `[*x*]{???}` -> `[<strong>x</strong>]{???}`, matching carve-js).
        // See carve/MAINTAINING.md.
        if ($afterBracket < $length && $text[$afterBracket] === '{') {
            $attrEnd = $this->findAttributeEnd($text, $afterBracket);
            if ($attrEnd !== null) {
                $attrStr = substr($text, $afterBracket + 1, $attrEnd - $afterBracket - 1);
                if ($this->isValidInlineAttrPayload($attrStr)) {
                    $span = new Span();
                    // The gating block is valid (real attributes or an
                    // empty/whitespace-only block). Apply and consume it here,
                    // then absorb any further consecutive attribute blocks
                    // (those stop at the first block that yields no attribute,
                    // leaving it literal -- e.g. `[x]{.a}{???}`).
                    $this->applyAttributesToNode($span, $attrStr);
                    $endPos = $this->applyConsecutiveAttributes($span, $text, $attrEnd + 1);
                    $this->parseInlinesAt($span, $linkText, $pos + 1);

                    return [
                        'node' => $span,
                        'pos' => $endPos,
                        'link_text' => $linkText,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Find the balanced closing `]` for a bracketed inline run.
     *
     * Escaped brackets and brackets inside code spans are opaque, matching the
     * link text scanner.
     *
     * @return int|null Position of the closing `]`, or null if unclosed
     */
    protected function findBalancedBracketEnd(string $text, int $openPos): ?int
    {
        return BracketScanner::balancedBracketEnd($text, $openPos);
    }

    /**
     * @return array{node: \MarkupCarve\Carve\Node\Inline\Image, pos: int}|null
     */
    protected function parseImage(string $text, int $pos): ?array
    {
        // Skip the !
        $result = $this->parseLink($text, $pos + 1);
        if ($result === null) {
            return null;
        }

        // Unclosed links can't be images
        if (isset($result['unclosed_link'])) {
            return null;
        }

        $link = $result['node'];
        if (!$link instanceof Link) {
            return null;
        }

        // Alt text is RAW, NOT parsed inline: emphasis, code spans and
        // backslashes are kept verbatim (`![*e* `c`](/p)` -> alt=`*e* `c``).
        // It ends where the LINK's text ends. An image has the same three forms
        // as a link and only the leading `!` and the `<img src>` output differ,
        // so the bracketed run is the run a link uses, closed by the same
        // balanced, escape- and literal-span-aware scan
        // (markup-carve/carve#1206, markup-carve/carve#1197).
        //
        // This used to be a second scan written here, and it agreed with
        // `findBalancedBracketEnd` on depth and on `\`, but not on the two
        // opaque runs that scan skips: a code span and an editorial comment.
        // So `![t`]`z](/i.png)` and `![t{# ] #}z](/i.png)` linked to the right
        // destination while the alt stopped at a `]` the parse had already
        // ruled was content. Reading the run parseLink closed removes the
        // second spelling instead of teaching it the same two exceptions.
        $alt = $result['link_text'];

        $image = new Image($link->getDestination() ?? '', $alt, $link->getTitle());

        // Transfer reference label for round-trip support
        if ($link->getReferenceLabel() !== null) {
            $image->setReferenceLabel($link->getReferenceLabel());
        }
        if ($link->getRawReferenceLabel() !== null) {
            $image->setRawReferenceLabel('!' . $link->getRawReferenceLabel());
        }

        // Transfer attributes from link to image
        foreach ($link->getAttributes() as $key => $value) {
            $image->setAttribute($key, $value);
        }

        return [
            'node' => $image,
            'pos' => $result['pos'],
        ];
    }

    /**
     * The non-ASCII characters carrying the Unicode White_Space property.
     *
     * ENUMERATED rather than written `\s` with the `u` modifier, because PCRE's
     * Unicode `\s` is not this property: it additionally matches U+180E
     * MONGOLIAN VOWEL SEPARATOR, which Unicode 6.3.0 removed from White_Space.
     * Spelling either half of `url_char` as `\s` decides part of the rule by
     * accident - and in the other direction JavaScript's `\s` matches U+FEFF
     * and misses U+0085, which is how carve-js and this engine reached opposite
     * answers on the byte order mark for reasons neither had chosen
     * (markup-carve/carve#860, carve-php#957).
     *
     * U+180E is still not a `url_char`, but as a FORMAT character rather than
     * as whitespace - which is the rule saying so rather than the host
     * language's character class saying it by accident.
     *
     * @var string
     */
    private const NON_ASCII_WHITESPACE_CLASS = '\x{0085}\x{00a0}\x{1680}\x{2000}-\x{200a}'
        . '\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}';

    /**
     * `url_char`, ASCII half - the enumeration in `resources/grammar.ebnf`.
     *
     * Written as what it ADMITS rather than as what it excludes, which is how
     * the grammar writes it. The two spellings agree on every printable
     * character - the nine punctuation exclusions stay out either way - and
     * they part on the CONTROL characters, which a negated class admits
     * because PHP's `\s` is not `\p{Cc}`. That is why a body carrying U+0001
     * used to link.
     *
     * @var string
     */
    private const URL_CHAR_ASCII_CLASS = "A-Za-z0-9\\-._~:\\/?#\\[\\]@!$&'()*+,;=%";

    /**
     * A `url_autolink` body: `scheme, ':', {url_char}+`.
     *
     * The SCHEME IS ASCII and does not move (`letter, {letter | digit | '+' |
     * '-' | '.'}`); only the body admits non-ASCII.
     *
     * OUTSIDE ASCII a `url_char` is any character that is not whitespace, not a
     * format character (General_Category Cf) and not a control character (Cc).
     * The control term is not redundant with the ASCII enumeration, which is
     * the trap it is written around: the C1 block U+0080-U+009F is non-ASCII
     * and non-whitespace apart from U+0085, so a rule spelled "non-ASCII and
     * not Cf" silently admits fourteen control characters while excluding
     * every C0 one.
     *
     * @var string
     */
    private const URL_AUTOLINK_RE = '/^[A-Za-z][A-Za-z0-9+.\-]*:'
        . '(?:[' . self::URL_CHAR_ASCII_CLASS . ']'
        . '|(?![\p{Cf}\p{Cc}' . self::NON_ASCII_WHITESPACE_CLASS . '])[^\x{00}-\x{7F}])+$/u';

    /**
     * The same production read as BYTES, for a subject that is not valid UTF-8.
     *
     * A rule stated in Unicode general categories cannot judge bytes that are
     * not characters, and refusing an autolink on that basis would decide a
     * malformed-input case no clause covers. So the ASCII half is applied as
     * written and every high byte is admitted - which is what this engine did
     * before, and is only ever reached when the pattern above could not run.
     *
     * @var string
     */
    private const URL_AUTOLINK_BYTES_RE = '/^[A-Za-z][A-Za-z0-9+.\-]*:'
        . '(?:[' . self::URL_CHAR_ASCII_CLASS . ']|[\x80-\xFF])+$/';

    /**
     * Whether the run between `<` and `>` is a `url_autolink` body.
     *
     * ONE recognizer, called from both `parseAutolink()` and
     * `findAutolinkEnd()`. The two carried independent copies of the same
     * pattern, so a change to one silently disagreed with the other.
     *
     * PART 3, AN AUTOLINK BODY ADMITS NON-ASCII AND EXCLUDES FORMAT CHARACTERS
     * (markup-carve/carve#844, markup-carve/carve#860). An internationalized
     * domain, an accented host, a non-ASCII path and a non-ASCII character that
     * is not a LETTER are all `url_char`s. The deciding argument is the
     * asymmetry with the inline form: `[t](https://<IDN>/)` links in all three
     * engines already, because `link_destination` admits `unicode_url_char`,
     * and one destination cannot answer differently on the character set
     * depending on the spelling.
     *
     * A FORMAT CHARACTER DOES NOT. It is invisible by definition, so a host
     * carrying one renders as the host WITHOUT it and links somewhere else.
     * That is a spoofing surface rather than an authoring convenience.
     *
     * `link_destination` is a DIFFERENT production and is unchanged: a format
     * character in an inline destination or a reference definition is still an
     * ordinary destination character.
     */
    private static function isUrlAutolinkBody(string $content): bool
    {
        if (preg_match(self::URL_AUTOLINK_RE, $content) === 1) {
            return true;
        }

        // A subject that is not valid UTF-8 makes `preg_match()` return FALSE
        // rather than 0, and the two are different answers: the rule is stated
        // in Unicode general categories, which say nothing about bytes that are
        // not characters. Judging such a body by the byte reading keeps this
        // engine's existing answer for malformed input rather than deciding a
        // case no clause covers.
        if (preg_last_error() === PREG_BAD_UTF8_ERROR) {
            return preg_match(self::URL_AUTOLINK_BYTES_RE, $content) === 1;
        }

        return false;
    }

    /**
     * @return array{node: \MarkupCarve\Carve\Node\Inline\Link, pos: int}|null
     */
    protected function parseAutolink(string $text, int $pos): ?array
    {
        $length = strlen($text);
        $end = strpos($text, '>', $pos);
        if ($end === false) {
            return null;
        }

        $content = substr($text, $pos + 1, $end - $pos - 1);

        // URL autolink (grammar.ebnf url_autolink). The url_char body excludes
        // whitespace and `<`/`>` plus `"` `\` `` ` `` `{` `}` `|` `^`; any of
        // those inside the body invalidates the construct (whole-literal), so
        // `<http://a.com/"q">` is NOT an autolink. Matches carve-js/carve-rs.
        if (self::isUrlAutolinkBody($content)) {
            $link = new Link($content);
            $link->setAutolink(true);
            // The label sits between the angle brackets, so one byte past the
            // opener. The Link itself is placed by the dispatch that called us.
            $label = new Text($content);
            $this->placeAt($label, $pos + 1, $pos + 1 + strlen($content));
            $link->appendChild($label);

            $endPos = $end + 1;

            // Check for trailing attributes: <url>{.class}{.more}
            if ($endPos < $length && $text[$endPos] === '{') {
                $endPos = $this->applyConsecutiveAttributes($link, $text, $endPos);
            }

            return [
                'node' => $link,
                'pos' => $endPos,
            ];
        }

        // Email autolink (grammar.ebnf email_autolink). email_char is
        // `[A-Za-z0-9._+-]`, with a MANDATORY `.TLD`; `[`/`]`/`:` are not email
        // chars, so `<a@[127.0.0.1]>` is literal, while a leading `.` is valid
        // so `<.a@b.com>` is a mailto link. Matches carve-js/carve-rs.
        if (preg_match('/^[A-Za-z0-9._+-]+@[A-Za-z0-9._+-]+\.[A-Za-z]+$/', $content)) {
            $link = new Link('mailto:' . $content);
            $link->setAutolink(true);
            // The label sits between the angle brackets, so one byte past the
            // opener. The Link itself is placed by the dispatch that called us.
            $label = new Text($content);
            $this->placeAt($label, $pos + 1, $pos + 1 + strlen($content));
            $link->appendChild($label);

            $endPos = $end + 1;

            // Check for trailing attributes: <email>{.class}{.more}
            if ($endPos < $length && $text[$endPos] === '{') {
                $endPos = $this->applyConsecutiveAttributes($link, $text, $endPos);
            }

            return [
                'node' => $link,
                'pos' => $endPos,
            ];
        }

        return null;
    }

    /**
     * Is the character at `$offset` escaped by a backslash?
     *
     * An ODD run of backslashes before it escapes it; an even run is literal
     * backslashes and the character still counts, so `\\{` is a literal
     * backslash followed by a real brace.
     */
    protected function isEscapedAt(string $text, int $offset): bool
    {
        $backslashes = 0;
        for ($i = $offset - 1; $i >= 0 && $text[$i] === '\\'; $i--) {
            $backslashes++;
        }

        return $backslashes % 2 === 1;
    }

    /**
     * Parse delimited inline elements like _emphasis_ or *strong*
     *
     * @param string $delimiter
     * @param int $pos
     * @param string $text
     * @param class-string<\MarkupCarve\Carve\Node\Node> $nodeClass
     *
     * @return array{node: \MarkupCarve\Carve\Node\Node, pos: int}|null
     */
    protected function parseDelimited(string $text, int $pos, string $delimiter, string $nodeClass): ?array
    {
        $length = strlen($text);

        // Reset the per-text no-closer memo when the scanned string changes.
        if ($text !== $this->emphNoCloseText) {
            $this->emphNoCloseText = $text;
            $this->emphNoCloseFrom = [];
        }

        // Check if this can be an opener (not preceded by whitespace for closer detection)
        $prevChar = $pos > 0 ? $text[$pos - 1] : ' ';
        $nextChar = $text[$pos + 1] ?? ' ';

        // Does a BRACED opener actually precede this delimiter? An escaped `{`
        // is a literal character and opens nothing, so `\{/x/}` holds an
        // ordinary bare pair while `{/x/}` holds the braced construct. The
        // closer search below needs the distinction (markup-carve/carve-php#1191).
        $openerIsBraced = $prevChar === '{' && !$this->isEscapedAt($text, $pos - 1);

        // Can't open if followed by whitespace. PART 7's four characters, not
        // `ctype_space()`, which also takes a VERTICAL TAB and a FORM FEED - so
        // `/<VT>a/` was left as literal text while `/!a/` was emphasis
        // (markup-carve/carve#963).
        if (StringUtil::isWhitespaceChar($nextChar)) {
            return null;
        }

        // Can't open if followed by } (closer marker in djot)
        if ($nextChar === '}') {
            return null;
        }

        // Keep the smart typography fat-arrow token literal for the symbol pass.
        if ($delimiter === '=' && $nextChar === '>') {
            return null;
        }

        // No same-type nesting (spec §4.2), unlike djot: a bare single-char
        // delimiter immediately PRECEDED or FOLLOWED by the same delimiter does
        // not open. A doubled delimiter is therefore literal text, never nested
        // same-type emphasis. This is uniform across all single-char
        // delimiters, so `**x**`, `~~x~~`, `^^x^^` stay literal exactly like
        // `//x//` and `__x__`.
        if ($prevChar === $delimiter || $nextChar === $delimiter) {
            return null;
        }

        // Bare single-char delimiters never open inside words. Slash and
        // underscore also keep their path-protection rule after slash.
        if ($prevChar === '_' || ctype_alnum($prevChar)) {
            return null;
        }
        if (($delimiter === '/' || $delimiter === '_') && $prevChar === '/') {
            return null;
        }

        // Look for the content and the closer right after the single opener.
        $searchStart = $pos + 1;

        // Bail in O(1) if an earlier same-delimiter opener already proved the
        // tail from here on holds no valid closer (see $emphNoCloseFrom).
        if (
            isset($this->emphNoCloseFrom[$delimiter])
            && $searchStart >= $this->emphNoCloseFrom[$delimiter]
        ) {
            return null;
        }

        // Whether this scan jumped over any region (attribute block, code span,
        // autolink, link destination, escape). The no-closer memo below is only
        // sound for a pure left-to-right walk: a skipped region may hold a `_x_`
        // that the MAIN loop still parses as emphasis (e.g. bare `](...)`/`{...}`
        // with no matching `[`/no attachable node), so a later opener inside that
        // region must not be short-circuited. See $emphNoCloseFrom.
        $scanSkipped = false;

        $searchPos = $searchStart;
        while ($searchPos < $length) {
            $char = $text[$searchPos];

            // A delimited comment is transparent to the surrounding span: a
            // delimiter in its body cannot close the span that contains it.
            if ($char === '{' && ($text[$searchPos + 1] ?? '') === '%') {
                $commentEnd = strpos($text, '%}', $searchPos + 2);
                if ($commentEnd !== false) {
                    $searchPos = $commentEnd + 2;
                    $scanSkipped = true;

                    continue;
                }
            }

            // Skip over attribute blocks {....} respecting quotes
            if ($char === '{') {
                $attrEnd = $this->findAttributeEnd($text, $searchPos);
                if ($attrEnd !== null) {
                    $searchPos = $attrEnd + 1;
                    $scanSkipped = true;

                    continue;
                }
            }

            // Skip over code spans `...`
            if ($char === '`') {
                $codeEnd = $this->findCodeSpanEnd($text, $searchPos);
                if ($codeEnd !== null) {
                    $searchPos = $codeEnd;
                    $scanSkipped = true;

                    continue;
                }

                // Unclosed backtick run: opaque to the end of the block, so no
                // closer can follow it and this delimiter cannot form emphasis.
                // The main loop consumes the unclosed run as a code span to
                // end-of-text, so no later opener is examined past it -- caching
                // this failure (when the walk so far was pure) stays
                // output-preserving.
                if (!$scanSkipped) {
                    $this->emphNoCloseFrom[$delimiter] = min(
                        $this->emphNoCloseFrom[$delimiter] ?? $searchStart,
                        $searchStart,
                    );
                }

                return null;
            }

            // Skip over autolinks <...>
            if ($char === '<') {
                $autolinkEnd = $this->findAutolinkEnd($text, $searchPos);
                if ($autolinkEnd !== null) {
                    $searchPos = $autolinkEnd;
                    $scanSkipped = true;

                    continue;
                }
            }

            // Skip over link destinations ](...)
            // This prevents emphasis delimiters inside URLs from closing emphasis
            // that started before the link. e.g. _[link](url_bar)_ should work.
            if ($char === ']' && $searchPos + 1 < $length && $text[$searchPos + 1] === '(') {
                $destEnd = $this->findLinkDestinationEnd($text, $searchPos + 1);
                if ($destEnd !== null) {
                    $searchPos = $destEnd;
                    $scanSkipped = true;

                    continue;
                }
            }

            // Skip escape sequences
            if ($char === '\\' && $searchPos + 1 < $length) {
                $searchPos += 2;
                $scanSkipped = true;

                continue;
            }

            // Check for closing delimiter
            if ($char === $delimiter) {
                // Check if this can be a closer (not preceded by whitespace)
                $beforeClose = $searchPos > 0 ? $text[$searchPos - 1] : ' ';
                if (!StringUtil::isWhitespaceChar($beforeClose)) {
                    // A braced closer (like `_}` or `*}`) belongs to a braced
                    // opener, so a bare opener must not steal it: in `{/x/}` the
                    // whole construct is the braced form's, not this path's.
                    //
                    // That only holds when a braced opener actually EXISTS. An
                    // ESCAPED brace is a literal `{` and opens nothing, so in
                    // `\{/x/}` the `/x/` is an ordinary bare pair and this is its
                    // closer. Skipping it unconditionally left the whole run
                    // literal, so carve-php rendered `\{/x/}` as `{/x/}` where
                    // carve-js and carve-rs render `{<em>x</em>}`
                    // (markup-carve/carve-php#1191). `escaped_char` in
                    // `resources/grammar.ebnf` is one backslash and ONE
                    // punctuation character; nothing in it suppresses the
                    // constructs after the character it escapes.
                    $afterClose = $text[$searchPos + 1] ?? '';
                    if ($afterClose === '}' && $openerIsBraced) {
                        $searchPos++;

                        continue;
                    }
                    // No same-type nesting (spec §4.2): the opener is a single
                    // delimiter (any longer run was peeled to literal above), so
                    // it pairs with the FIRST valid closer (innermost matching).
                    // Any trailing delimiters of a closing run stay literal:
                    // `**x**` -> `*<strong>x</strong>*`, `~b~~` -> `<s>b</s>~`.
                    $actualClose = $searchPos;

                    // Bare single-char delimiters never close before an
                    // alphanumeric (right word boundary, grammar §9). Unlike the
                    // opener's left boundary, `_` does NOT block a closer.
                    $afterClose = $text[$actualClose + 1] ?? '';
                    if ($afterClose !== '' && ctype_alnum($afterClose)) {
                        $searchPos = $actualClose + 1;

                        continue;
                    }

                    // Check content isn't empty
                    $content = substr($text, $pos + 1, $actualClose - $pos - 1);
                    if ($content === '') {
                        $searchPos = $actualClose + 1;

                        continue;
                    }

                    $node = new $nodeClass();
                    $this->parseInlinesAt($node, $content, $pos + 1);

                    $endPos = $actualClose + 1;

                    // Check for trailing attributes: _text_{.class}{.more}
                    if ($endPos < $length && $text[$endPos] === '{') {
                        $endPos = $this->applyConsecutiveAttributes($node, $text, $endPos);
                    }

                    return [
                        'node' => $node,
                        'pos' => $endPos,
                    ];
                }
            }

            $searchPos++;
        }

        // Scanned to end-of-text with no valid closer. When the walk skipped no
        // region, every position in [searchStart, end) was examined and rejected
        // for a position-only reason, so every later same-delimiter opener (a
        // strict suffix) fails too: remember the start so those openers bail in
        // O(1). If the walk skipped a region, the memo is unsound (see above).
        if (!$scanSkipped) {
            $this->emphNoCloseFrom[$delimiter] = min(
                $this->emphNoCloseFrom[$delimiter] ?? $searchStart,
                $searchStart,
            );
        }

        return null;
    }

    /**
     * Parse the combined bold-italic span /*text*\/, producing a Strong
     * node wrapping an Emphasis node.
     *
     * @param string $text
     * @param int $pos Position of the opening '/'
     *
     * @return array{node: \MarkupCarve\Carve\Node\Node, pos: int}|null
     */
    protected function parseBoldItalic(string $text, int $pos): ?array
    {
        $length = strlen($text);
        $start = $pos + 2; // skip '/*'

        if ($start >= $length || StringUtil::isWhitespaceChar($text[$start])) {
            return null;
        }

        // A bold-italic span needs its `*/` closer. Without this guard every
        // `/*` opener scans to end-of-text, so an unclosed run is O(n^2). The
        // memoized strrpos bails in O(1) when no `*/` lies ahead.
        if (!$this->closerExistsFrom($text, '*/', $start)) {
            return null;
        }

        $searchPos = $start;
        while ($searchPos + 1 < $length) {
            if ($text[$searchPos] === '{' && ($text[$searchPos + 1] ?? '') === '%') {
                $commentEnd = strpos($text, '%}', $searchPos + 2);
                if ($commentEnd !== false) {
                    $searchPos = $commentEnd + 2;

                    continue;
                }
            }

            if ($text[$searchPos] === '`') {
                $codeEnd = $this->findCodeSpanEnd($text, $searchPos);
                if ($codeEnd !== null) {
                    $searchPos = $codeEnd + 1;

                    continue;
                }

                // Unclosed backtick run: opaque to the end of the block, so no
                // closer can follow it.
                return null;
            }

            if ($text[$searchPos] === '*' && $text[$searchPos + 1] === '/') {
                $content = substr($text, $start, $searchPos - $start);
                if ($content === '' || StringUtil::isWhitespaceChar($content[strlen($content) - 1])) {
                    $searchPos++;

                    continue;
                }

                $emphasis = new Emphasis();
                // The inner emphasis of a combined `/*...*/`: its source is the
                // body between the delimiters, which the outer Strong wraps.
                $this->placeAt($emphasis, $start, $searchPos);
                $this->parseInlinesAt($emphasis, $content, $start);
                $strong = new Strong();
                // Record that the author used the COMBINED form. The nested
                // spelling yields the same Strong>Emphasis tree, so the writer
                // needs the mark to reproduce what was written (PART 11 section 6).
                $strong->setBoldItalic(true);
                $strong->appendChild($emphasis);

                $endPos = $searchPos + 2;
                if ($endPos < $length && $text[$endPos] === '{') {
                    $endPos = $this->applyConsecutiveAttributes($strong, $text, $endPos);
                }

                return [
                    'node' => $strong,
                    'pos' => $endPos,
                ];
            }

            $searchPos++;
        }

        return null;
    }

    /**
     * Editorial comment {# ... #} -> a `critic_comment` node.
     * Content is literal (spaces preserved), matching carve-js.
     *
     * @return array{node: \MarkupCarve\Carve\Node\Node, pos: int}|null
     */
    protected function parseEditorialComment(string $text, int $pos): ?array
    {
        if (substr($text, $pos, 2) !== '{#') {
            return null;
        }
        // No `#}` ahead -> not an editorial comment; bail in O(1) so a run of
        // `{#` openers without a closer stays linear (strpos would otherwise
        // scan to end-of-text at every opener).
        if (!$this->closerExistsFrom($text, '#}', $pos + 2)) {
            return null;
        }
        $close = strpos($text, '#}', $pos + 2);
        if ($close === false) {
            return null;
        }
        $comment = new CriticComment(substr($text, $pos + 2, $close - $pos - 2));

        return ['node' => $comment, 'pos' => $close + 2];
    }

    /**
     * Parse braced inline syntax: {+insert+}, {-delete-},
     * forced delimiter spans, {~old~>new~} substitution, {'} and {"}.
     *
     * @return array{node: \MarkupCarve\Carve\Node\Node, pos: int}|array{nodes: list<\MarkupCarve\Carve\Node\Node>, pos: int}|null
     */
    protected function parseBracedInline(string $text, int $pos): ?array
    {
        $length = strlen($text);
        if ($pos + 2 >= $length) {
            return null;
        }

        $marker = $text[$pos + 1];

        // Handle braced quotes: {'} or {"} followed by optional quotes then }
        // {''} = left single quote + right single quote
        // {""} = left double quote + right double quote
        // {'} = right single quote only, {"} = right double quote only
        if ($marker === "'" || $marker === '"') {
            // Count consecutive quotes
            $quoteCount = 1;
            $quotePos = $pos + 2;
            while ($quotePos < $length && $text[$quotePos] === $marker) {
                $quoteCount++;
                $quotePos++;
            }
            // Must be followed by closing }
            if ($quotePos < $length && $text[$quotePos] === '}') {
                // Generate quotes based on count
                $openQuote = $marker === "'" ? $this->openSingleQuote : $this->openDoubleQuote;
                $closeQuote = $marker === "'" ? $this->closeSingleQuote : $this->closeDoubleQuote;

                // For pairs like {''}, output left + right
                // For single {'}, output apostrophe (always U+2019), {"} output close double
                if ($quoteCount === 1) {
                    $result = $marker === "'" ? $this->apostrophe : $closeQuote;
                } elseif ($quoteCount === 2) {
                    $result = $openQuote . $closeQuote;
                } else {
                    // For more, alternate open/close
                    $result = '';
                    for ($i = 0; $i < $quoteCount; $i++) {
                        $result .= ($i % 2 === 0) ? $openQuote : $closeQuote;
                    }
                }

                return [
                    'node' => new Text($result),
                    'pos' => $quotePos + 1,
                ];
            }
        }

        // Editorial substitution {~old~>new~} -> <del>old</del><ins>new</ins>.
        // Skip the forward scan when no `~}` closer lies ahead (a run of `{~`
        // openers would otherwise each walk to end-of-text -> O(n^2)).
        if ($marker === '~' && $this->closerExistsFrom($text, '~}', $pos + 2)) {
            $searchPos = $pos + 2;
            while ($searchPos < $length - 1) {
                if ($text[$searchPos] === '~' && $text[$searchPos + 1] === '}') {
                    $content = substr($text, $pos + 2, $searchPos - $pos - 2);
                    if (str_contains($content, '~>')) {
                        [$old, $new] = explode('~>', $content, 2);

                        return ['node' => new Substitution($old, $new), 'pos' => $searchPos + 2];
                    }

                    break;
                }
                $searchPos++;
            }
        }

        $nodeClass = match ($marker) {
            '+' => Insert::class,
            '-' => Delete::class,
            '/' => Emphasis::class,
            '~' => Strike::class,
            '^' => Superscript::class,
            '_' => Underline::class,
            '*' => Strong::class,
            ',' => Subscript::class,
            '=' => Highlight::class,
            default => null,
        };

        if ($nodeClass === null) {
            return null;
        }

        // A braced span needs its `marker}` closer. Without this guard every
        // `{+` / `{-` / `{*` ... opener walks to end-of-text looking for the
        // closer, so an unclosed run is O(n^2). strrpos (memoized) short-circuits
        // in O(1) when no `marker}` lies at or after the content start.
        if (!$this->closerExistsFrom($text, $marker . '}', $pos + 2)) {
            return null;
        }

        // Find closing: marker}
        // For braced syntax, we allow spaces inside (unlike bare delimiters)
        $searchPos = $pos + 2;
        while ($searchPos < $length - 1) {
            if ($text[$searchPos] === $marker && $text[$searchPos + 1] === '}') {
                $content = substr($text, $pos + 2, $searchPos - $pos - 2);
                $node = new $nodeClass();
                $this->parseInlinesAt($node, $content, $pos + 2);

                $endPos = $searchPos + 2;

                // Check for trailing attributes: {*text*}{.class}{.more}
                // But NOT if it's another braced inline like {*text*}{=more=}
                if ($endPos < $length && $text[$endPos] === '{') {
                    $nextChar = $text[$endPos + 1] ?? '';
                    // Braced inline markers that should NOT be treated as attributes
                    if (!in_array($nextChar, ['=', '+', '-', '/', '~', '^', '_', '*', ','], true)) {
                        $endPos = $this->applyConsecutiveAttributes($node, $text, $endPos);
                    }
                }

                return [
                    'node' => $node,
                    'pos' => $endPos,
                ];
            }
            $searchPos++;
        }

        return null;
    }

    /**
     * Return the last (possibly multibyte UTF-8) character of $buffer, or an
     * empty string when the buffer is empty. Used to determine the rendered
     * character preceding a smart quote.
     */

    /**
     * The previously emitted character, for the quote open/close decision.
     *
     * The decision keys off the last character already produced. That used to
     * be the tail of the text buffer, but a smart-typography node flushes the
     * buffer, so the glyph it produced would otherwise be invisible here and an
     * adjacent quote would misread its context: an opening curly quote is one
     * of the few characters that puts the NEXT quote in opening context, so
     * losing it flips `""` from opening to closing.
     */
    protected function previousConvertedChar(Node $parent, string $textBuffer): string
    {
        $last = $this->lastCharOf($textBuffer);
        if ($last !== '') {
            return $last;
        }

        $children = $parent->getChildren();
        $previous = $children === [] ? null : $children[array_key_last($children)];

        if ($previous instanceof SmartPunctuation) {
            $glyph = $previous->getGlyph() ?? (SmartPunctuation::GLYPHS[$previous->getKind()] ?? '');
            if ($glyph !== '') {
                return $glyph;
            }
        }

        // An escaped character still flanks as the character it is: `\{"q"` has
        // to open the quote exactly as `{"q"` does. carve-js decides flanking
        // from the literal, and carve-php diverged here because the escape
        // became a node and the buffer went empty - which only became visible
        // once the formatter re-derived quotes instead of emitting the glyph.
        if ($previous instanceof EscapedText) {
            $literal = $this->lastCharOf($previous->getContent());
            if ($literal !== '') {
                return $literal;
            }
        }

        // Any other flushed state with prior output is word-adjacent, i.e.
        // closing context (carve-js treats this the same way).
        return $previous === null ? '' : 'x';
    }

    /**
     * The node kind for a resolved quote glyph.
     */
    protected function smartQuoteKind(string $glyph): string
    {
        return match ($glyph) {
            $this->openDoubleQuote => 'left_double_quote',
            $this->closeDoubleQuote => 'right_double_quote',
            $this->openSingleQuote => 'left_single_quote',
            $this->closeSingleQuote, $this->apostrophe => 'right_single_quote',
            default => 'text',
        };
    }

    protected function lastCharOf(string $buffer): string
    {
        if ($buffer === '') {
            return '';
        }

        // Walk back over any UTF-8 continuation bytes (0b10xxxxxx) to the
        // start of the final character.
        $i = strlen($buffer) - 1;
        while ($i > 0 && (ord($buffer[$i]) & 0xC0) === 0x80) {
            $i--;
        }

        return substr($buffer, $i);
    }

    protected function parseSmartQuote(string $prevConverted, string $text, int $pos, string $quote): string
    {
        $nextChar = $text[$pos + 1] ?? ' ';

        // A straight quote curls OPENING when the preceding (already-rendered)
        // character is start-of-content, whitespace (incl. NBSP), an opening
        // curly quote (nested-quote context), or one of the operator /
        // opening-punctuation characters `( [ { = : - /` (plus the en/em
        // dashes). Sentence punctuation (`. , ; ! ?`), letters, digits and
        // closing brackets (`] )`) stay CLOSING. Mirrors the canonical oracle
        // carve-js `isQuoteOpenContext` (decision SQ). See carve/MAINTAINING.md.
        $openContext = $this->isQuoteOpenContext($prevConverted);

        if ($quote === '"') {
            return $openContext ? $this->openDoubleQuote : $this->closeDoubleQuote;
        }

        // A single quote directly before a digit is always a literal
        // apostrophe (always U+2019, locale-independent): decade elision
        // `'70s` -> `’70s`, even in an opening context.
        if (ctype_digit($nextChar)) {
            return $this->apostrophe;
        }

        // Single quote in an opening context is an OPENING quote
        // (`'word'`, `rock 'n' roll` -> the first `'`).
        if ($openContext) {
            return $this->openSingleQuote;
        }

        // Outside an opening context: a literal apostrophe (always U+2019,
        // locale-independent) mid-word (`don't`, `it's`); otherwise a
        // locale-dependent CLOSING single quote (`'Hello'` -> the second `'`).
        if (preg_match('/\w/u', $nextChar)) {
            return $this->apostrophe;
        }

        return $this->closeSingleQuote;
    }

    /**
     * Whether a straight quote sits in an opening context, given the
     * previously rendered character $prevConverted (empty string =
     * start-of-content): start, whitespace (incl. NBSP), an opening curly
     * quote, or one of `( [ { = : - /` (or an en/em dash). Mirrors the
     * canonical oracle carve-js `isQuoteOpenContext`.
     */
    protected function isQuoteOpenContext(string $prevConverted): bool
    {
        if ($prevConverted === '') {
            return true;
        }

        // A non-breaking space is whitespace, so a quote after it opens --
        // both a literal U+00A0 and Carve's explicit `\ ` form, which is
        // carried as the U+E000 private-use placeholder until rendering. The
        // opening curly quotes (U+201C `“` / U+2018 `‘`) before a quote are a
        // nested-quote open context.
        if (
            $prevConverted === "\xC2\xA0"
            || $prevConverted === "\u{E000}"
            || $prevConverted === "\u{201C}"
            || $prevConverted === "\u{2018}"
        ) {
            return true;
        }

        // $prevConverted is one character; a multibyte char (e.g. a dash or
        // curly quote not matched above) is not in the single-byte opener set.
        // A newline / carriage return (a soft line break) is NOT an opening
        // context: a straight quote right after a wrapped line is word-adjacent
        // and stays CLOSING (`a"b\n""` -> `a”b\n””`), matching carve-js (which
        // treats a flushed buffer at a soft break as word context, not start).
        if (
            strlen($prevConverted) === 1
            && StringUtil::isWhitespaceChar($prevConverted)
            && $prevConverted !== "\n"
            && $prevConverted !== "\r"
        ) {
            return true;
        }

        // En/em dashes (U+2013 / U+2014) also open a following quote.
        if ($prevConverted === "\u{2013}" || $prevConverted === "\u{2014}") {
            return true;
        }

        return strlen($prevConverted) === 1 && strpos('([{=:-/', $prevConverted) !== false;
    }

    /**
     * Smart typography for arrows, comparison operators, and (c)/(r)/(tm).
     * Longest-first so `<->` beats `<-` and `(tm)` beats `(c)`. Mirrors the
     * carve-js SMART_TOKENS table (lowercase only).
     *
     * @return array{0: string, 1: int}|null [replacement, consumedLength]
     */
    protected function parseSmartSymbol(string $text, int $pos): ?array
    {
        static $map = [
            '<->' => "\u{2194}",
            '(tm)' => "\u{2122}",
            '->' => "\u{2192}",
            '<-' => "\u{2190}",
            '=>' => "\u{21D2}",
            '<=' => "\u{2264}",
            '>=' => "\u{2265}",
            '!=' => "\u{2260}",
            '+-' => "\u{00B1}",
            '(c)' => "\u{00A9}",
            '(r)' => "\u{00AE}",
        ];

        foreach ($map as $needle => $repl) {
            if (substr($text, $pos, strlen($needle)) === $needle) {
                return [$repl, strlen($needle)];
            }
        }

        return null;
    }

    /**
     * The node kind for a smart-symbol source run.
     *
     * Kept separate from parseSmartSymbol() so that method's return contract
     * stays as-is for any subclass overriding it.
     */
    protected function smartSymbolKind(string $source): string
    {
        return match ($source) {
            '<->' => 'left_right_arrow',
            '->' => 'rightwards_arrow',
            '<-' => 'leftwards_arrow',
            '=>' => 'rightwards_double_arrow',
            '<=' => 'less_than_or_equal',
            '>=' => 'greater_than_or_equal',
            '!=' => 'not_equal',
            '+-' => 'plus_minus',
            '(c)' => 'copyright',
            '(r)' => 'registered',
            '(tm)' => 'trademark',
            default => 'text',
        };
    }

    /**
     * @return array{text: string, pos: int}
     */
    protected function parseSmartDash(string $text, int $pos): array
    {
        $length = strlen($text);
        $dashCount = 0;

        while ($pos + $dashCount < $length && $text[$pos + $dashCount] === '-') {
            $dashCount++;
        }

        // Convert dashes according to djot algorithm:
        // 1. If divisible by 3, all em-dashes
        // 2. If divisible by 2, all en-dashes
        // 3. Otherwise, em-dashes first, then en-dashes, with minimal en-dashes
        $emDash = "\u{2014}"; // —
        $enDash = "\u{2013}"; // –

        if ($dashCount === 1) {
            return [
                'text' => '-',
                'pos' => $pos + $dashCount,
            ];
        }

        if ($dashCount % 3 === 0) {
            // All em-dashes
            return [
                'text' => str_repeat($emDash, (int)($dashCount / 3)),
                'pos' => $pos + $dashCount,
            ];
        }

        if ($dashCount % 2 === 0) {
            // All en-dashes
            return [
                'text' => str_repeat($enDash, (int)($dashCount / 2)),
                'pos' => $pos + $dashCount,
            ];
        }

        // Mixed: find combination emCount*3 + enCount*2 = dashCount with minimal enCount
        // Start with max em-dashes and find the remainder for en-dashes
        $emCount = (int)($dashCount / 3);
        $remainder = $dashCount % 3;

        // remainder can be 1 or 2 (not 0, we handled that above)
        if ($remainder === 1) {
            // Can't make 1 with en-dashes, so trade one em-dash for two en-dashes
            // 3 + 1 = 4 → 2*2 = 4 ✓
            $emCount--;
            $enCount = 2;
        } else {
            // remainder is 2, which is one en-dash
            $enCount = 1;
        }

        return [
            'text' => str_repeat($emDash, $emCount) . str_repeat($enDash, $enCount),
            'pos' => $pos + $dashCount,
        ];
    }

    /**
     * Parse inline attributes that apply to preceding word: word{.class}
     *
     * @return array{textBuffer: string, pos: int}|null
     */
    protected function parseInlineAttributes(string $text, int $pos, string $textBuffer, Node $parent): ?array
    {
        $length = strlen($text);

        // Find the closing brace, handling quoted strings
        $attrEnd = $this->findAttributeEnd($text, $pos);
        if ($attrEnd === null) {
            return null;
        }

        $attrStr = substr($text, $pos + 1, $attrEnd - $pos - 1);

        // A genuinely empty/whitespace-only block (`{}`, `{ }`) abutting a word
        // or an inline node is NOT consumed -- it stays literal (`hi{}` ->
        // `hi{}`, `*x*{}` -> `<strong>x</strong>{}`, `[x]{}{}` keeps the second
        // block), matching carve-js / carve-rs. The one place an empty block is
        // meaningful, the `[text]{}` span form, is handled by the bracket path
        // before this standalone-attribute handler runs.
        if (trim($attrStr, StringUtil::WHITESPACE_CHARS) === '') {
            return null;
        }

        // Check if this looks like valid attributes (starts with ., #, or key=)
        // Exclude _ * = + - ~ ^ which are braced inline markers
        if (!preg_match('/^[.#:a-zA-Z]/', $attrStr)) {
            return null;
        }

        // The block must yield a valid attribute, else it is not an attribute
        // block (§14): a digit-first name (`.123`, `#1`, `2=v`) or other
        // unrecognized content makes the whole `{...}` stay literal. Decline
        // so the caller emits `{` literally and re-parses the content.
        // THE INLINE SURFACE, if this is ever wired up. `parseInlineAttributes`
        // has NO caller in this package: Carve dropped bare-word attribute
        // attachment, and `word{.a}` is literal text. Narrowing the gate here
        // changes nothing today - a mutation removing it survives, and that is
        // the reason rather than a gap in the tests - but leaving the BLOCK
        // gate on an inline surface would be wrong the day someone calls it.
        if (!$this->isValidInlineAttrPayload($attrStr)) {
            return null;
        }

        // Find the preceding word to attach attributes to
        // A word is a sequence of alphanumeric characters (plus some allowed chars)
        $precedingWord = '';
        $wordStart = strlen($textBuffer);

        // Scan backwards to find word boundary
        // Per djot spec: a word is a sequence of non-ASCII-whitespace characters
        // However, smart/curly quotes act as word boundaries for attribute attachment
        while ($wordStart > 0) {
            $char = $textBuffer[$wordStart - 1];

            // Stop at ASCII whitespace
            if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
                break;
            }

            // Check for multi-byte configured quote characters
            // These act as word boundaries for attribute attachment
            foreach ($this->getConfiguredQuoteStrings() as $quoteStr) {
                $quoteLen = strlen($quoteStr);
                if ($wordStart >= $quoteLen && substr($textBuffer, $wordStart - $quoteLen, $quoteLen) === $quoteStr) {
                    break 2;
                }
            }

            $wordStart--;
        }

        $textBufferLen = strlen($textBuffer);
        if ($wordStart < $textBufferLen) {
            $precedingWord = substr($textBuffer, $wordStart);
            $textBuffer = substr($textBuffer, 0, $wordStart);
        }

        // No inline element abuts the block (it sits at the start of the
        // content or after whitespace), so it is not an attribute block:
        // decline here and let the caller emit `{` literally and re-parse the
        // content inline (grammar PART 9 §14, inline_span requires a `[...]`
        // host). Consuming it would silently drop the braces and lose content.
        if ($precedingWord === '') {
            return null;
        }

        // Flush any text before the word
        $this->flushText($parent, $textBuffer);

        // Create a span with the word and apply attributes
        $span = new Span();
        $span->appendChild(new Text($precedingWord));
        $this->applyAttributesToNode($span, $attrStr);
        $parent->appendChild($span);

        return [
            'textBuffer' => '',
            'pos' => $attrEnd + 1,
        ];
    }

    /**
     * Get all unique configured quote strings for word boundary detection
     *
     * @return array<string>
     */
    protected function getConfiguredQuoteStrings(): array
    {
        return array_unique([
            $this->openDoubleQuote,
            $this->closeDoubleQuote,
            $this->openSingleQuote,
            $this->closeSingleQuote,
            $this->apostrophe,
        ]);
    }

    /**
     * Whether a `{...}` payload is a valid attribute block.
     *
     * Valid means it yields at least one attribute under the actual attribute
     * grammar (AttributeParser), OR it is empty/whitespace/comment-only -- a
     * valid empty block that carve-php materializes as a bare <span> so a
     * default-attribute extension can target it. A block carrying unrecognized
     * content (`{???}`, `{=y=}`, `{"{y}"}`) is not an attribute block at all,
     * so the whole bracketed run stays literal text (PART 9 §14).
     */

    /**
     * Whether a `{...}` payload is a valid INLINE attribute block.
     *
     * The same oracle as `isValidAttrPayload()` plus PART 4's space-only
     * interior (markup-carve/carve#906). A SEPARATE METHOD rather than a flag,
     * so a call site added later has to say which surface it is on: the
     * block-attribute LINE keeps `whitespace` at all three of its slots, and a
     * fix that narrowed both at once fails on corpus category 273.
     */
    public function isValidInlineAttrPayload(string $attrStr): bool
    {
        return AttributeParser::inlineInteriorIsSpaceOnly($attrStr) && $this->isValidAttrPayload($attrStr);
    }

    public function isValidAttrPayload(string $attrStr): bool
    {
        // Strip every RECOGNIZED token; if anything non-whitespace remains the
        // block is invalid and stays literal (§14). A name (key, class, id)
        // is a grammar `identifier`: letter/`_` first, then letters, digits,
        // `_` or `-` -- so a digit-first, hyphen-first or COLON-bearing name
        // is NOT recognized, and one bad name invalidates the WHOLE block even
        // mixed with valid ones, matching carve-js and carve-rs. A colon is
        // still legal inside an unquoted VALUE (`{k=a:b}`, `unquoted_value`).
        // Booleans and an invalid unquoted VALUE (which is tolerated and
        // skipped) stay accepted.
        $rest = $attrStr;
        // Quoted key=values first, so `%`, dots and braces inside quotes are
        // protected from the shorthand patterns.
        $rest = preg_replace(
            '/(?:(?<=[ \t\r\n])|^)[a-zA-Z_][a-zA-Z0-9_-]*=(?:"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"|\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\')/',
            ' ',
            $rest,
        ) ?? $rest;
        // PART 7's four characters, not PHP's default trim charlist, which takes a
        // VERTICAL TAB and not a FORM FEED. `{#a<VT>.b}` left the character behind
        // after the shorthand patterns and the trim ate it, so the block validated
        // as TWO attributes where `{#a!.b}` - an ordinary content character in the
        // same position - is rejected (markup-carve/carve#963).
        if (trim($rest, StringUtil::WHITESPACE_CHARS) === '') {
            return true;
        }
        // A SEPARATOR IS REQUIRED BETWEEN TWO ATTRIBUTES. `attribute_list` is
        // `attribute, {space+, attribute}` (PART 7), so `{.a.b}`, `{#i.c}` and
        // `{.a#i}` are not attribute blocks and stay literal; the executable
        // spec has always refused them.
        //
        // ANCHORED, NOT STRIPPED. The strip pipeline this replaces could not
        // express the rule: it rewrote each recognized item to a SPACE, which
        // manufactured the separator the next item needed. Adding a lookbehind
        // to the class and id patterns fixed `{.a.b}` and left `{.a#i}`
        // accepted, because by the time the id pattern ran, the class it
        // followed had already become a space.
        $item = '(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')';
        $item = '(?:[a-zA-Z_][a-zA-Z0-9_-]*=' . $item . ')'
            . '|(?::(?:[a-zA-Z0-9]{1,8}(?:-[a-zA-Z0-9]{1,8})*)?)'
            . '|(?:[a-zA-Z_][a-zA-Z0-9_-]*=[^ \t\r\n}]+)'
            . '|(?:\.[a-zA-Z_][a-zA-Z0-9_-]*)'
            . '|(?:#[a-zA-Z_][a-zA-Z0-9_-]*)'
            . '|(?:[a-zA-Z][a-zA-Z0-9_-]*)';
        $ws = '[ \t\r\n]';

        return preg_match(
            '/^' . $ws . '*(?:(?:' . $item . ')(?:' . $ws . '+(?:' . $item . '))*' . $ws . '*)?$/D',
            $rest,
        ) === 1;
    }

    /**
     * Find the end of an attribute block, handling quoted strings
     */
    protected function findAttributeEnd(string $text, int $pos): ?int
    {
        $length = strlen($text);

        // A block needs a closing `}`. Without this guard, every `{` runs the
        // char-by-char scan below to end-of-text, so an unclosed run like
        // `[x]{` repeated is O(n^2). strrpos (memoized) short-circuits when no
        // `}` lies at or after the block start.
        if (!$this->closerExistsFrom($text, '}', $pos + 1)) {
            return null;
        }

        $i = $pos + 1;
        $inQuote = null;
        $depth = 1;

        while ($i < $length) {
            $char = $text[$i];

            // An inline attribute block is single-line: a newline before the
            // closing `}` means this is not an inline attr block, so the `{`
            // stays literal (`[x]{.a\n.b}` is text). Matches carve-js / carve-rs.
            if ($char === "\n") {
                return null;
            }

            // Handle escape sequences
            if ($char === '\\' && $i + 1 < $length) {
                $i += 2;

                continue;
            }

            // Handle quotes
            if ($inQuote !== null) {
                if ($char === $inQuote) {
                    $inQuote = null;
                }
                $i++;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $inQuote = $char;
                $i++;

                continue;
            }

            if ($char === '{') {
                $depth++;
                // Closing an unmatched-brace depth of `d` needs `d` more `}`. If
                // the depth outruns the `}` still ahead, the block can NEVER
                // balance, so the scan below would only run to end-of-text and
                // return null -- bail now (byte-identical, same null). This turns
                // the pathological `[x]{` repeated + one far `}` (nested `{` that
                // never balances) from O(n^2) into O(1) per opener. Only reached
                // on a nested `{` (never for a flat, valid attribute block), and
                // the suffix count is a memoized per-text table, so a document of
                // many valid `[x]{.a}` blocks pays nothing here.
                if ($depth > $this->closeBraceSuffixCount($text, $i + 1)) {
                    return null;
                }
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }

            $i++;
        }

        return null;
    }

    /**
     * Find the end of a code span starting at $pos
     *
     * @return int|null Position after the closing backticks, or null if not found
     */
    protected function findCodeSpanEnd(string $text, int $pos): ?int
    {
        return BracketScanner::codeSpanEnd($text, $pos);
    }

    /**
     * Find the end of a link destination starting at $pos (which points to '(').
     *
     * This is a simpler version that only handles the destination part,
     * not the full link syntax. Used to skip over URL content when scanning
     * for emphasis closers.
     *
     * @return int|null Position after the closing ), or null if not found
     */
    protected function findLinkDestinationEnd(string $text, int $pos): ?int
    {
        $length = strlen($text);
        if ($pos >= $length || $text[$pos] !== '(') {
            return null;
        }

        // Short-circuit when no `)` lies at or after the destination start: the
        // scan could only run to end-of-text and return null. The last-paren
        // position is memoized per text (strrpos runs once), so this stays O(1)
        // and avoids building the jump table when no `)` can follow.
        $lastCloseParen = $this->lastCloseParenPos($text);
        if ($lastCloseParen === false || $lastCloseParen < $pos + 1) {
            return null;
        }

        // A `)` lies ahead: resolve the one that BALANCES this `(` in O(1) via
        // the per-text match table. Without a table, a run of openers each
        // reaching a `](` whose only closer is one far `)` (input `_a](`
        // repeated + `)`) would re-walk the same tail per opener -> O(n^2).
        // Matching rather than taking the first unescaped `)` is what keeps
        // this agreeing with the destination scan in parseLink, which balances.
        $close = $this->matchingCloseParen($text, $pos);
        if ($close === false) {
            // Nothing balances this `(`, so it opens no destination and there is
            // no link here to skip. The first unescaped `)` is still a sound
            // place to resume from -- it is what this returned before
            // destinations balanced -- and taking it keeps the skip O(1). Left
            // to walk instead, a run of openers whose only closer is one far `)`
            // (input `_a](` repeated + `)`) re-walks the same tail per opener,
            // which is the quadratic case the tables above exist to prevent.
            $close = $this->nextUnescapedCloseParen($text, $pos + 1);
        }

        return $close === false ? null : $close + 1;
    }

    /**
     * Position of the last `)` in $text (strrpos), or false when there is none.
     *
     * Memoized per text: the same string instance is compared pointer-cheap, so
     * repeated link-destination scans over one text pay the C-level strrpos once.
     *
     * @return int|false
     */
    protected function lastCloseParenPos(string $text): int|false
    {
        if ($text !== $this->lastCloseParenText) {
            $this->lastCloseParenText = $text;
            $this->lastCloseParenPos = strrpos($text, ')');
        }

        return $this->lastCloseParenPos;
    }

    /**
     * Whether $needle occurs at or after position $from in $text.
     *
     * strrpos (the last occurrence) is memoized per (text, needle): if the last
     * occurrence is at or after $from, then some occurrence lies at or after
     * $from. Lets a fixed-closer tail scanner bail in O(1) when its closing
     * delimiter cannot appear ahead, instead of walking to end-of-text. See
     * $closerLastPos.
     */
    protected function closerExistsFrom(string $text, string $needle, int $from): bool
    {
        if ($text !== $this->closerLastText) {
            $this->closerLastText = $text;
            $this->closerLastPos = [];
        }
        if (!array_key_exists($needle, $this->closerLastPos)) {
            $this->closerLastPos[$needle] = strrpos($text, $needle);
        }
        $last = $this->closerLastPos[$needle];

        return $last !== false && $last >= $from;
    }

    /**
     * Number of `}` at index >= $from in $text. Backed by a per-text suffix
     * table (see $braceCloseSuffix), built once and reused across every
     * attribute scan over that text so the depth check stays O(1) per nested
     * brace instead of re-counting the tail.
     */
    protected function closeBraceSuffixCount(string $text, int $from): int
    {
        if ($text !== $this->braceCloseSuffixText) {
            $this->braceCloseSuffixText = $text;
            $length = strlen($text);
            $suffix = array_fill(0, $length + 1, 0);
            for ($p = $length - 1; $p >= 0; $p--) {
                $suffix[$p] = $suffix[$p + 1] + ($text[$p] === '}' ? 1 : 0);
            }
            $this->braceCloseSuffix = $suffix;
        }

        return $this->braceCloseSuffix[$from] ?? 0;
    }

    /**
     * Index of the `)` that balances the `(` at $pos, or false when there is
     * none.
     *
     * Backed by a per-text table built in one left-to-right pass with a stack,
     * so every destination skip over the same text is an O(1) lookup rather
     * than its own walk. An escaped character neither opens nor closes a level,
     * matching the destination scan in parseLink.
     *
     * @return int|false
     */
    protected function matchingCloseParen(string $text, int $pos): int|false
    {
        if ($text !== $this->matchingCloseParenText) {
            $this->matchingCloseParenText = $text;
            $length = strlen($text);
            $match = [];
            $openers = [];
            for ($p = 0; $p < $length; $p++) {
                $char = $text[$p];
                if ($char === '\\') {
                    $p++;

                    continue;
                }
                if ($char === '(') {
                    $openers[] = $p;
                } elseif ($char === ')' && $openers !== []) {
                    $match[array_pop($openers)] = $p;
                }
            }
            $this->matchingCloseParen = $match;
        }

        return $this->matchingCloseParen[$pos] ?? false;
    }

    /**
     * Index of the first UNESCAPED `)` at or after $from, or false when none.
     *
     * Backed by a per-text jump table (see $unescCloseParen) so repeated
     * link-destination skips over one text never re-walk the same tail. A `)`
     * is escaped when the char before it was consumed by a backslash under a
     * left-to-right walk; findLinkDestinationEnd only starts scanning right
     * after a `(` (a clean, non-backslash boundary), so the global escape parse
     * matches what a fresh walk from that start would compute.
     *
     * @return int|false
     */
    protected function nextUnescapedCloseParen(string $text, int $from): int|false
    {
        if ($text !== $this->unescCloseParenText) {
            $this->unescCloseParenText = $text;
            $this->unescCloseParen = $this->buildUnescapedCloseParenTable($text);
        }
        if ($this->unescCloseParen === null) {
            return false;
        }
        $pos = $this->unescCloseParen[$from] ?? -1;

        return $pos < 0 ? false : $pos;
    }

    /**
     * Build the next-unescaped-`)` table for $text: `next[$p]` is the smallest
     * index >= $p holding an unescaped `)`, or -1 when none follows. Returns
     * null when the text contains no `)` at all (nothing to look up).
     *
     * @return array<int, int>|null
     */
    protected function buildUnescapedCloseParenTable(string $text): ?array
    {
        if (strrpos($text, ')') === false) {
            return null;
        }

        $length = strlen($text);
        // Standard escape parse: a backslash consumes (escapes) the next char.
        $escaped = array_fill(0, $length, false);
        $i = 0;
        while ($i < $length) {
            if ($text[$i] === '\\' && $i + 1 < $length) {
                $escaped[$i + 1] = true;
                $i += 2;

                continue;
            }
            $i++;
        }

        $next = array_fill(0, $length + 1, -1);
        for ($p = $length - 1; $p >= 0; $p--) {
            $next[$p] = ($text[$p] === ')' && !$escaped[$p]) ? $p : $next[$p + 1];
        }

        return $next;
    }

    /**
     * Find the end of an autolink starting at $pos
     *
     * @return int|null Position after the closing >, or null if not a valid autolink
     */
    protected function findAutolinkEnd(string $text, int $pos): ?int
    {
        $length = strlen($text);

        if ($pos >= $length || $text[$pos] !== '<') {
            return null;
        }

        $end = strpos($text, '>', $pos);
        if ($end === false) {
            return null;
        }

        $content = substr($text, $pos + 1, $end - $pos - 1);

        // Check if it's a valid URL autolink (same url_char body as parseAutolink).
        if (self::isUrlAutolinkBody($content)) {
            return $end + 1;
        }

        // Check if it's a valid email autolink (same email_char body as parseAutolink).
        if (preg_match('/^[A-Za-z0-9._+-]+@[A-Za-z0-9._+-]+\.[A-Za-z]+$/', $content)) {
            return $end + 1;
        }

        return null;
    }

    /**
     * Apply attributes from a string to a node
     */
    protected function applyAttributesToNode(Node $node, string $attrStr): void
    {
        AttributeParser::applyToNode($node, $attrStr);
    }

    /**
     * Apply all consecutive attribute blocks to a node
     *
     * Per djot spec, multiple consecutive attribute blocks like {.foo}{.bar}
     * should merge. Classes combine, later values override earlier ones.
     *
     * @return int The final position after all attribute blocks
     */
    protected function applyConsecutiveAttributes(Node $node, string $text, int $startPos): int
    {
        $length = strlen($text);
        $pos = $startPos;

        while ($pos < $length && $text[$pos] === '{') {
            $attrEnd = $this->findAttributeEnd($text, $pos);
            if ($attrEnd === null) {
                break;
            }

            $attrStr = substr($text, $pos + 1, $attrEnd - $pos - 1);
            // A `{...}` that is not a valid attribute block is literal text (PART
            // 9 §15), not an empty block to consume. Stop here and leave it in
            // the stream (so e.g. `{=hl=}`, `{ }`, `{???}`, and a `%`-bearing
            // block like `{.c % n %}` render literally after a node instead of
            // being applied or silently dropped). Uses the SAME validity oracle
            // as the standalone attribute path, so `*x*{.c % n %}` matches
            // `word{.c % n %}` (and carve-js / carve-rs). The inline-span branch
            // in parseLink() handles a leading empty/whitespace block explicitly
            // before delegating here.
            if (AttributeParser::parse($attrStr) === [] || !$this->isValidInlineAttrPayload($attrStr)) {
                break;
            }
            $this->applyAttributesToNode($node, $attrStr);
            $pos = $attrEnd + 1;
        }

        return $pos;
    }

    /**
     * Parse inline footnote ^[content].
     *
     * @return array{node: \MarkupCarve\Carve\Node\Inline\InlineFootnote, pos: int}|null
     */
    protected function parseInlineFootnote(string $text, int $pos): ?array
    {
        $length = strlen($text);
        if ($pos + 1 >= $length || $text[$pos] !== '^' || $text[$pos + 1] !== '[') {
            return null;
        }

        $close = $this->findBalancedBracketEnd($text, $pos + 1);
        if ($close === null) {
            return null;
        }

        $content = substr($text, $pos + 2, $close - $pos - 2);
        // PART 7's four characters. PHP's default charlist takes a VERTICAL TAB
        // and not a FORM FEED, so `x^[<VT>]` was literal text while `x^[<FF>]`
        // was a footnote whose body held the character - one emptiness test
        // answering two ways (markup-carve/carve#963).
        if (trim($content, StringUtil::WHITESPACE_CHARS) === '') {
            return null;
        }

        $node = new InlineFootnote();
        $this->parseInlinesAt($node, $content, $pos + 2, false);

        $endPos = $close + 1;
        if ($endPos < $length && $text[$endPos] === '{') {
            $endPos = $this->applyConsecutiveAttributes($node, $text, $endPos);
        }

        return [
            'node' => $node,
            'pos' => $endPos,
        ];
    }

    /**
     * Parse footnote reference [^label]
     *
     * A resolved reference yields a FootnoteRef node (with any trailing attribute
     * block attached); an unresolved one yields the literal `[^label]` source as a
     * Text node, discarding an orphan trailing attribute block.
     *
     * @return array{node: \MarkupCarve\Carve\Node\Inline\FootnoteRef|\MarkupCarve\Carve\Node\Inline\Text, pos: int}|null
     */
    protected function parseFootnoteRef(string $text, int $pos): ?array
    {
        // A label is a physical-line identifier. A definition marker cannot
        // cross a newline, so accepting one in a reference creates an id that
        // no valid definition can bind.
        if (!preg_match('/\G\[\^([^\]\r\n]+)\]/', $text, $matches, 0, $pos)) {
            return null;
        }

        $label = $matches[1];

        // Warn if footnote is not defined
        if (!$this->blockParser->hasFootnote($label)) {
            [$warnLine, $warnColumn] = $this->lineAndColumnAt($pos);
            $this->blockParser->addUndefinedFootnoteWarning($label, $warnLine, $warnColumn);

            // An UNRESOLVED footnote reference RENDERS literally as `[^label]`,
            // but it is still a footnote_ref node - which is what lets it keep a
            // trailing attribute block.
            //
            // It used to become a Text node and the attribute was consumed and
            // thrown away, so `Text[^a]{.ref}.` lost `{.ref}` from the tree
            // entirely and the canonical writer emitted `Text[^a].` where
            // carve-js and carve-rs emit `Text[^a]{.ref}.`. The old comment here
            // said those two "drop the orphan attribute" - true of their HTML,
            // and not of their AST, which keeps it (carve#352, carve#405).
            //
            // Emitting a node rather than returning null still stops the generic
            // inline-span path from claiming `[^a]{.ref}` as
            // `<span class="ref">^a</span>`. An empty or invalid block stays
            // literal, so `[^a]{}` and `[^a]{???}` are unchanged.
            $node = new FootnoteRef($label);
            $node->setUnresolved(true);
            $endPos = $pos + strlen($matches[0]);
            $length = strlen($text);
            if ($endPos < $length && $text[$endPos] === '{') {
                $endPos = $this->applyConsecutiveAttributes($node, $text, $endPos);
            }

            return [
                'node' => $node,
                'pos' => $endPos,
            ];
        }

        $node = new FootnoteRef($label);
        $endPos = $pos + strlen($matches[0]);

        // A trailing `{...}` attaches to the noteref <a> (grammar PART 9 §note;
        // mirrors the inline-footnote `^[...]{attrs}` path).
        $length = strlen($text);
        if ($endPos < $length && $text[$endPos] === '{') {
            $endPos = $this->applyConsecutiveAttributes($node, $text, $endPos);
        }

        return [
            'node' => $node,
            'pos' => $endPos,
        ];
    }

    /**
     * Parse math: $`...` for inline, $$`...` for display
     *
     * @return array{node: \MarkupCarve\Carve\Node\Inline\Math, pos: int}|null
     */
    protected function parseMath(string $text, int $pos): ?array
    {
        $length = strlen($text);

        // Check for display math $$
        $display = false;
        $dollarCount = 0;
        while ($pos + $dollarCount < $length && $text[$pos + $dollarCount] === '$') {
            $dollarCount++;
        }

        if ($dollarCount >= 2) {
            $display = true;
            $startPos = $pos + 2;
        } else {
            $startPos = $pos + 1;
        }

        // Must be followed by backtick
        if ($startPos >= $length || $text[$startPos] !== '`') {
            return null;
        }

        // Count opening backticks
        $backtickCount = 0;
        while ($startPos + $backtickCount < $length && $text[$startPos + $backtickCount] === '`') {
            $backtickCount++;
        }

        $contentStart = $startPos + $backtickCount;

        // Find a closing run of EXACTLY $backtickCount backticks, not one that is
        // part of a LONGER run -- math reuses a code span (grammar
        // `math_inline = '$', code_span`), so `$`a``b`` closes at the final
        // single backtick (content `a``b`), not the doubled run in the middle.
        $closingBackticks = str_repeat('`', $backtickCount);
        $searchPos = $contentStart;
        $closePos = false;
        while ($searchPos < $length) {
            $cand = strpos($text, $closingBackticks, $searchPos);
            if ($cand === false) {
                break;
            }
            $before = $cand > 0 ? $text[$cand - 1] : '';
            $after = $cand + $backtickCount < $length ? $text[$cand + $backtickCount] : '';
            if ($before === '`' || $after === '`') {
                // Part of a longer backtick run: skip the whole run and continue.
                $searchPos = $cand;
                while ($searchPos < $length && $text[$searchPos] === '`') {
                    $searchPos++;
                }

                continue;
            }
            $closePos = $cand;

            break;
        }

        if ($closePos === false) {
            $content = substr($text, $contentStart);
            if ($content === '') {
                return null;
            }

            return [
                'node' => new Math(rtrim($content, " \t\r\n"), $display),
                'pos' => $length,
            ];
        }

        // Math reuses the verbatim span, so it takes the same single-space strip
        // as a code span (carve-js and carve-rs both do). Without it the content
        // kept its padding while the serializer padded it again, so `` $` x ` ``
        // grew by two spaces on every fmt pass.
        $content = $this->stripVerbatimPadding(
            substr($text, $contentStart, $closePos - $contentStart),
        );
        $node = new Math($content, $display);

        // A trailing `{...}` applies attributes to the math span (math reuses the
        // code-span attribute slot). EXCEPT `{=format}`, the raw-inline form,
        // which is code-span-only and not inherited by math: leave it literal.
        $endPos = $closePos + $backtickCount;
        $isRawAttempt = $endPos + 1 < $length && $text[$endPos] === '{' && $text[$endPos + 1] === '=';
        if (!$isRawAttempt && $endPos < $length && $text[$endPos] === '{') {
            $endPos = $this->applyConsecutiveAttributes($node, $text, $endPos);
        }

        return [
            'node' => $node,
            'pos' => $endPos,
        ];
    }

    /**
     * Inline literal (PART 9 §27): a `!` prefix on a verbatim code span,
     * mirroring the `$`-math prefix (`literal_inline = '!', code_span`). The
     * span content is captured verbatim with the code-span single-space strip,
     * then HTML-escaped and emitted by every renderer with the `<code>` wrapper
     * dropped -- it is prose, not code. A trailing `{...}` is the ORDINARY
     * attribute block (as a code span carries), NOT the raw `{=format}` form.
     * Requires a CLOSED span: a bare `!` before an unclosed run stays literal
     * text and the run becomes an ordinary (unclosed) code span, exactly as a
     * bare `$` before an unclosed run behaves.
     *
     * @return array{node: \MarkupCarve\Carve\Node\Inline\LiteralInline, pos: int}|null
     */
    protected function parseLiteralInline(string $text, int $pos): ?array
    {
        $length = strlen($text);

        // $pos is the `!`; the backtick run must start immediately after it.
        $startPos = $pos + 1;
        if ($startPos >= $length || $text[$startPos] !== '`') {
            return null;
        }

        $backtickCount = 0;
        while ($startPos + $backtickCount < $length && $text[$startPos + $backtickCount] === '`') {
            $backtickCount++;
        }

        $contentStart = $startPos + $backtickCount;

        // Find a closing run of EXACTLY $backtickCount backticks that is not
        // part of a LONGER run (mirrors the code span / math). An unclosed run
        // is NOT a literal -- return null so the `!` stays literal text.
        $closingBackticks = str_repeat('`', $backtickCount);
        $searchPos = $contentStart;
        $closePos = false;
        while ($searchPos < $length) {
            $cand = strpos($text, $closingBackticks, $searchPos);
            if ($cand === false) {
                break;
            }
            $before = $cand > 0 ? $text[$cand - 1] : '';
            $after = $cand + $backtickCount < $length ? $text[$cand + $backtickCount] : '';
            if ($before === '`' || $after === '`') {
                $searchPos = $cand;
                while ($searchPos < $length && $text[$searchPos] === '`') {
                    $searchPos++;
                }

                continue;
            }
            $closePos = $cand;

            break;
        }

        if ($closePos === false) {
            return null;
        }

        $content = substr($text, $contentStart, $closePos - $contentStart);

        $content = $this->stripVerbatimPadding($content);

        $node = new LiteralInline($content);

        // A trailing `{...}` is the ordinary attribute block, EXCEPT the raw
        // `{=format}` form, which is code-span-only and not inherited here:
        // leave it literal (mirrors math).
        $endPos = $closePos + $backtickCount;
        $isRawAttempt = $endPos + 1 < $length && $text[$endPos] === '{' && $text[$endPos + 1] === '=';
        if (!$isRawAttempt && $endPos < $length && $text[$endPos] === '{') {
            $endPos = $this->applyConsecutiveAttributes($node, $text, $endPos);
        }

        return [
            'node' => $node,
            'pos' => $endPos,
        ];
    }

    /**
     * Parse an inline extension :type[content].
     *
     * @return array{node: \MarkupCarve\Carve\Node\Node, pos: int}|null
     */
    protected function parseInlineExtension(string $text, int $pos): ?array
    {
        // extension_name = identifier (grammar.ebnf §988-989, 1147):
        // identifier = (letter | '_'), {letter | digit | '_' | '-'}. A
        // leading underscore is a valid extension name, so `:_[x]` -> a
        // `ext-_` span (decision I).
        if (!preg_match('/\G:([a-zA-Z_][a-zA-Z0-9_-]*)\[([^\]]*)\]/', $text, $matches, 0, $pos)) {
            return null;
        }

        $node = new InlineExtension($matches[1]);
        $this->parseInlinesAt($node, $matches[2], $pos + strlen($matches[1]) + 2);

        $endPos = $pos + strlen($matches[0]);
        $length = strlen($text);
        if ($endPos < $length && $text[$endPos] === '{') {
            $endPos = $this->applyConsecutiveAttributes($node, $text, $endPos);
        }

        return [
            'node' => $node,
            'pos' => $endPos,
        ];
    }

    /**
     * Parse symbol :name:
     *
     * @return array{node: \MarkupCarve\Carve\Node\Inline\Symbol, pos: int}|null
     */
    protected function parseSymbol(string $text, int $pos): ?array
    {
        // PHP accepts negative string offsets, so `$text[-1]` is the FINAL
        // byte rather than an absent byte. At position zero that made the
        // symbol's left boundary depend on how the whole inline run ended:
        // `:ok:` parsed, while `:ok: heading` did not because its final `g`
        // was mistaken for the byte before the opening colon.
        $previous = $pos > 0 ? $text[$pos - 1] : '';
        if ($previous !== '' && ($previous === '_' || ctype_alnum($previous))) {
            return null;
        }

        // Match :name: - \G anchors at offset position, avoiding extra strpos check.
        // The first name char is a letter, digit, `+` or `-` (so the reaction
        // shortcodes `:+1:` / `:-1:` parse), but never `_`: `:_x_:` would steal
        // from underline. Matching the symbol at the opening `:` also gives it
        // precedence over smart typography, so `:+-:` is the symbol `+-`, not a
        // `±` between colons (grammar PART 9 §7).
        if (!preg_match('/\G:([a-zA-Z0-9+-][\w+-]*):/', $text, $matches, 0, $pos)) {
            return null;
        }

        $symbol = new Symbol($matches[1]);
        $endPos = $pos + strlen($matches[0]);
        $length = strlen($text);

        // Check for trailing attributes: :symbol:{.class}{.more}
        if ($endPos < $length && $text[$endPos] === '{') {
            $endPos = $this->applyConsecutiveAttributes($symbol, $text, $endPos);
        }

        return [
            'node' => $symbol,
            'pos' => $endPos,
        ];
    }

    /**
     * Normalize a reference label for lookup.
     *
     * - Strip inline formatting markers (_, *, etc.)
     * - Collapse whitespace (including newlines) to single spaces
     * - Trim leading/trailing whitespace
     */
}
