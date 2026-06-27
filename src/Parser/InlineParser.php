<?php

declare(strict_types=1);

namespace Carve\Parser;

use Carve\Node\Block\Comment;
use Carve\Node\Document;
use Carve\Node\Inline\Abbreviation;
use Carve\Node\Inline\CaptionNumber;
use Carve\Node\Inline\Code;
use Carve\Node\Inline\Delete;
use Carve\Node\Inline\Emphasis;
use Carve\Node\Inline\EscapedText;
use Carve\Node\Inline\FootnoteRef;
use Carve\Node\Inline\HardBreak;
use Carve\Node\Inline\HeadingRef;
use Carve\Node\Inline\Highlight;
use Carve\Node\Inline\Image;
use Carve\Node\Inline\InlineExtension;
use Carve\Node\Inline\InlineFootnote;
use Carve\Node\Inline\Insert;
use Carve\Node\Inline\Link;
use Carve\Node\Inline\Math;
use Carve\Node\Inline\RawInline;
use Carve\Node\Inline\RawText;
use Carve\Node\Inline\SoftBreak;
use Carve\Node\Inline\Span;
use Carve\Node\Inline\Strike;
use Carve\Node\Inline\Strong;
use Carve\Node\Inline\Subscript;
use Carve\Node\Inline\Substitution;
use Carve\Node\Inline\Superscript;
use Carve\Node\Inline\Symbol;
use Carve\Node\Inline\Text;
use Carve\Node\Inline\Underline;
use Carve\Node\Node;
use Carve\Parser\Utility\AttributeParser;
use Closure;

/**
 * Inline parser for Djot
 *
 * Handles emphasis, strong, links, images, code spans, etc.
 */
class InlineParser
{
    /**
     * @var array<array{type: string, char: string, pos: int, node: \Carve\Node\Node}>
     */
    protected array $delimiterStack = [];

    /**
     * Current source line number for error reporting (0-indexed)
     */
    protected int $currentLine = 0;

    /**
     * Custom inline patterns: array of [pattern => callback]
     * Callback receives (string $match, array $groups, InlineParser $parser)
     * and should return a Node or null
     *
     * @var array<string, callable(string, array<string>, self): ?\Carve\Node\Node>
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
     * Document-wide feature accumulators.
     *
     * Set true the first time the corresponding node is created anywhere during
     * a single document parse, and never reset until the next document parse
     * begins (see resetDocumentFeatures()). The block parser reads them after
     * parsing to flag the Document so the cross-reference resolver can skip
     * passes whose feature is absent. They are NOT reset per block (unlike
     * captionNumberEmitted, which is a per-caption signal).
     */
    protected bool $documentHasLink = false;

    protected bool $documentHasHeadingRef = false;

    protected bool $documentHasCaptionNumber = false;

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
     * @param callable(string, array<string>, self): ?\Carve\Node\Node $callback
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
     * @param \Closure(string, int, \Carve\Parser\MatcherContext): (array{node: \Carve\Node\Node, end: int}|null) $matcher
     * @param int $priority
     * @param string|null $triggerChars Literal first bytes this matcher fires on; null = runs everywhere
     */
    public function addInlineMatcher(Closure $matcher, int $priority = 0, ?string $triggerChars = null): void
    {
        $this->registerInlineMatcher($matcher, $priority, triggerChars: $triggerChars);
    }

    /**
     * @param \Closure(string, int, \Carve\Parser\MatcherContext): (array{node: \Carve\Node\Node, end: int}|null) $matcher
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
     * @param \Carve\Node\Node $parent
     * @param string $text
     * @param int $sourceLine Source line number (0-indexed) for error reporting
     * @param bool $captionContext
     */

    /**
     * Reset the document-wide feature accumulators at the start of a new
     * document parse. The InlineParser instance is reused across parses, so the
     * flags must be cleared before each top-level document.
     */
    public function resetDocumentFeatures(): void
    {
        $this->documentHasLink = false;
        $this->documentHasHeadingRef = false;
        $this->documentHasCaptionNumber = false;
    }

    /**
     * Copy the accumulated feature flags onto the document so the renderer's
     * cross-reference resolver can skip passes whose feature is absent.
     */
    public function exportDocumentFeatures(Document $document): void
    {
        $document->setHasLinks($this->documentHasLink);
        $document->setHasHeadingRefs($this->documentHasHeadingRef);
        $document->setHasNumberedCaptions($this->documentHasCaptionNumber);
    }

    /**
     * Whether a node produced by an external inline matcher contains a link
     * (the matcher may return the link as the top node or wrapped in a
     * container). Used to keep documentHasLink complete for extension-produced
     * links; only called while no link has been seen yet, so it short-circuits
     * to a no-op once the flag is set.
     */
    protected function subtreeContainsLink(Node $node, int $depth = 0): bool
    {
        if ($node instanceof Link) {
            return true;
        }

        if ($depth >= self::MAX_INLINE_DEPTH) {
            return false;
        }

        foreach ($node->getChildren() as $child) {
            if ($this->subtreeContainsLink($child, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    public function parse(Node $parent, string $text, int $sourceLine = 0, bool $captionContext = false): void
    {
        $this->delimiterStack = [];
        $this->currentLine = $sourceLine;
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

    public function parseHeading(Node $parent, string $text, int $sourceLine = 0): void
    {
        $previousWordAttributes = $this->wordAttributesEnabled;
        $this->wordAttributesEnabled = false;

        try {
            $this->parse($parent, $text, $sourceLine);
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
     * Maximum `[`-nesting depth findBalancedBracketEnd will scan before bailing
     * with null. Caps the per-scan cost so a long run of openers cannot drive
     * the bracket scan to O(n^2) (see findBalancedBracketEnd). Generously deeper
     * than any real document and well above MAX_INLINE_DEPTH, so a balanced
     * bracket that the parser could actually act on is always within range and
     * output stays byte-identical.
     *
     * @var int
     */
    protected const MAX_BRACKET_NESTING = 1000;

    /**
     * Current inline-recursion depth (see self::MAX_INLINE_DEPTH).
     */
    protected int $inlineDepth = 0;

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
                $textBuffer .= $char;
                $pos++;

                continue;
            }

            $nextChar = $text[$pos + 1] ?? '';

            // Check for escape sequences
            if ($char === '\\' && $pos + 1 < $length) {
                $escaped = $text[$pos + 1];
                if ($escaped === "\n") {
                    // Hard break
                    $this->flushText($parent, $textBuffer);
                    $textBuffer = '';
                    $parent->appendChild(new HardBreak());
                    $pos += 2;

                    continue;
                }
                // Check for hard break: \TAB or \ followed by optional whitespace then newline
                if ($escaped === "\t" || $escaped === ' ') {
                    // Look ahead for end of line (optional trailing whitespace then newline)
                    $lookAhead = $pos + 2;
                    while ($lookAhead < $length && ($text[$lookAhead] === ' ' || $text[$lookAhead] === "\t")) {
                        $lookAhead++;
                    }
                    if ($lookAhead < $length && $text[$lookAhead] === "\n") {
                        // This is a hard break - strip trailing whitespace from text buffer
                        $textBuffer = rtrim($textBuffer, " \t");
                        $this->flushText($parent, $textBuffer);
                        $textBuffer = '';
                        $parent->appendChild(new HardBreak());
                        $pos = $lookAhead + 1;

                        continue;
                    }
                    // Not at end of line: `\ ` is Carve's explicit NBSP form.
                    // Backslash-tab is not an escape; only ASCII punctuation escapes.
                    if ($escaped === ' ') {
                        // Non-breaking space - use placeholder that renderer converts to &nbsp;
                        // We use U+E000 (private use area) to distinguish from literal NBSP
                        $textBuffer .= "\u{E000}";
                        $pos += 2;

                        continue;
                    }
                }
                if (strpos('!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~', $escaped) !== false) {
                    // Create EscapedText node for round-trip support
                    $this->flushText($parent, $textBuffer);
                    $textBuffer = '';
                    $parent->appendChild(new EscapedText($escaped));
                    $pos += 2;

                    continue;
                }
            }

            // Soft break (newline)
            if ($char === "\n") {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $parent->appendChild(new SoftBreak());
                $pos++;

                continue;
            }

            if ($char === '#' && $this->isCaptionNumberPlaceholder($text, $pos)) {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $parent->appendChild(new CaptionNumber());
                $this->captionNumberEmitted = true;
                $this->documentHasCaptionNumber = true;
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
                $parent->appendChild(new Comment($content));
                $pos = $end;

                continue;
            }

            // Math: $`...` or $$`...`
            if ($char === '$') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseMath($text, $pos);
                if ($result !== null) {
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
                // Not math, add to buffer
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
                        $this->parseInlines($parent, $result['link_text']);
                        $parent->appendChild(new Text(']('));
                        $pos = $result['continue_pos'];

                        continue;
                    }
                    // At this point, result has node/pos (not unclosed_link)
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Heading cross-reference: </#id> (before autolink)
            if ($char === '<' && ($text[$pos + 1] ?? '') === '/' && ($text[$pos + 2] ?? '') === '#') {
                if (preg_match('/\G<\/#([^>\s]+)>/u', $text, $hm, 0, $pos)) {
                    $this->flushText($parent, $textBuffer);
                    $textBuffer = '';
                    $parent->appendChild(new HeadingRef($hm[1]));
                    $this->documentHasHeadingRef = true;
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
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Inline footnote: ^[content]. Takes precedence over superscript.
            if ($this->footnoteRecognitionEnabled && $char === '^' && $nextChar === '[') {
                $result = $this->parseInlineFootnote($text, $pos);
                if ($result !== null) {
                    $this->flushText($parent, $textBuffer);
                    $textBuffer = '';
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Superscript: ^text^
            if ($char === '^') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseDelimited($text, $pos, '^', Superscript::class);
                if ($result !== null) {
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Strikethrough: ~text~
            if ($char === '~') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseDelimited($text, $pos, '~', Strike::class);
                if ($result !== null) {
                    $parent->appendChild($result['node']);
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Subscript: ,text,
            if ($char === ',') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseDelimited($text, $pos, ',', Subscript::class);
                if ($result !== null) {
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
                    $parent->appendChild($comment['node']);
                    $pos = $comment['pos'];

                    continue;
                }

                $attrEnd = $this->findAttributeEnd($text, $pos);
                if ($attrEnd !== null) {
                    $attrStr = substr($text, $pos + 1, $attrEnd - $pos - 1);
                    if (trim($attrStr) !== '' && trim($this->removeAttributeComments($attrStr)) === '') {
                        $this->flushText($parent, $textBuffer);
                        $textBuffer = '';
                        $pos = $attrEnd + 1;

                        continue;
                    }
                }

                // Then try special braced syntax.
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseBracedInline($text, $pos);
                if ($result !== null) {
                    if (isset($result['nodes'])) {
                        foreach ($result['nodes'] as $bracedNode) {
                            $parent->appendChild($bracedNode);
                        }
                    } else {
                        $parent->appendChild($result['node']);
                    }
                    $pos = $result['pos'];

                    continue;
                }
            }

            // Smart quotes
            if ($char === '"' || $char === "'") {
                // The opening/closing decision keys off the PREVIOUS rendered
                // character (mirrors carve-js, which inspects the output
                // buffer): a `'`/`"` right after a converted `“`/`‘` opens. So
                // pass the last char already emitted into $textBuffer. When the
                // buffer was flushed by a prior inline node (code, emphasis,
                // link, or a soft/hard line break), the quote is word-adjacent
                // -> CLOSING context. carve-js treats any flushed-buffer state
                // with prior output as `'x'` (closing), so a quote after a
                // wrapped line stays closing (`a"b\n""` -> `a”b\n””`). A truly
                // empty run with no prior children is start-of-content.
                $prevConverted = $this->lastCharOf($textBuffer);
                if ($prevConverted === '' && $parent->hasChildren()) {
                    $prevConverted = 'x';
                }
                $smartQuote = $this->parseSmartQuote($prevConverted, $text, $pos, $char);
                $textBuffer .= $smartQuote;
                $pos++;

                continue;
            }

            // Smart dashes
            if ($char === '-' && $nextChar === '-') {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                $result = $this->parseSmartDash($text, $pos);
                $textBuffer .= $result['text'];
                $pos = $result['pos'];

                continue;
            }

            // Ellipsis
            if ($char === '.' && substr($text, $pos, 3) === '...') {
                $textBuffer .= "\u{2026}";
                $pos += 3;

                continue;
            }

            // Smart symbols: arrows, comparison operators, (c)/(r)/(tm).
            // Runs after the escape check, so `\->` etc. are already absorbed.
            $symbol = $this->parseSmartSymbol($text, $pos);
            if ($symbol !== null) {
                $textBuffer .= $symbol[0];
                $pos += $symbol[1];

                continue;
            }

            $matchResult = $this->tryInlineMatchers($text, $pos);
            if ($matchResult !== null) {
                $this->flushText($parent, $textBuffer);
                $textBuffer = '';
                // An inline matcher (e.g. autolink, wikilink, heading-reference
                // extensions) can produce a link node; keep documentHasLink
                // complete for these externally built subtrees.
                if (!$this->documentHasLink && $this->subtreeContainsLink($matchResult['node'])) {
                    $this->documentHasLink = true;
                }
                $parent->appendChild($matchResult['node']);
                $pos = $matchResult['end'];

                continue;
            }

            // Regular character
            $textBuffer .= $char;
            $pos++;
        }

        $this->flushText($parent, $textBuffer);
        $this->footnoteRecognitionEnabled = $previousFootnoteRecognition;
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
        if ($text === '') {
            return;
        }

        // Check if there are any abbreviations to process
        $abbreviations = $this->blockParser->getAbbreviations();
        if ($abbreviations === []) {
            $parent->appendChild(new Text($text));

            return;
        }

        // Process abbreviations in the text
        $this->flushTextWithAbbreviations($parent, $text, $abbreviations);
    }

    /**
     * Flush text while replacing abbreviations with Abbreviation nodes
     *
     * @param \Carve\Node\Node $parent
     * @param string $text
     * @param array<string, string> $abbreviations
     */
    protected function flushTextWithAbbreviations(Node $parent, string $text, array $abbreviations): void
    {
        // Cache the regex pattern for abbreviations (built once per document)
        if ($this->cachedAbbreviations !== $abbreviations) {
            // Sort abbreviations by length (longest first) to match longer abbreviations first
            $abbrKeys = array_keys($abbreviations);
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

        foreach ($parts as $part) {
            if (isset($abbreviations[$part])) {
                // This is an abbreviation match
                $abbr = new Abbreviation($abbreviations[$part]);
                $abbr->appendChild(new Text($part));
                $parent->appendChild($abbr);
            } else {
                // Regular text
                $parent->appendChild(new Text($part));
            }
        }
    }

    /**
     * @return array{node: \Carve\Node\Node, end: int}|null
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
     * @return array{node: \Carve\Node\Inline\Code|\Carve\Node\Inline\RawInline, pos: int}|null
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

            // Strip one leading and one trailing space when the closed span is
            // space-wrapped but not entirely spaces (CommonMark/djot rule).
            if (strlen($content) >= 2 && $content[0] === ' ' && $content[strlen($content) - 1] === ' ') {
                if (strspn($content, ' ') !== strlen($content)) {
                    $content = substr($content, 1, -1);
                }
            }

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

        return null;
    }

    /**
     * @return array{node: \Carve\Node\Inline\Link|\Carve\Node\Inline\Span|\Carve\Node\Inline\Text|\Carve\Node\Inline\RawText, pos: int}|array{unclosed_link: true, link_text: string, continue_pos: int}|null
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

            while ($urlEnd < $length) {
                if ($text[$urlEnd] === '\\' && $urlEnd + 1 < $length) {
                    $urlEnd++;
                } elseif ($text[$urlEnd] === ')') {
                    break;
                } else {
                    $urlEnd++;
                }
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
                $title = null;
                if (
                    preg_match('/^([\s\S]*?)\s+"((?:\\\\"|[^"])*)"$/', $raw, $tm)
                    || preg_match('/^([\s\S]*?)\s+\'((?:\\\\\'|[^\'])*)\'$/', $raw, $tm)
                    || preg_match('/^([\s\S]*?)\s+\(([^()]*)\)$/', $raw, $tm)
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
                if ($url === '' || preg_match('/\s/', $url) || (str_starts_with($url, '<') && str_ends_with($url, '>'))) {
                    return null;
                }

                // Process escape sequences in URL (e.g., \* -> *)
                $url = preg_replace('/\\\\(.)/', '$1', $url) ?? $url;
                $link = new Link($url, $title);
                $this->documentHasLink = true;
                $this->parseInlines($link, $linkText);

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

                // For empty reference [text][], use link text as reference
                // In this case, normalize to strip formatting markers
                if ($ref === '') {
                    $ref = $this->normalizeReferenceLabel($linkText);
                } else {
                    // Explicit reference [text][ref] - only normalize whitespace, keep formatting chars
                    $ref = preg_replace('/\s+/', ' ', trim($ref)) ?? $ref;
                }

                // Store original bracket content before normalization
                $originalRefBracket = substr($text, $afterBracket + 1, $refEnd - $afterBracket - 1);

                $refDef = $originalRefBracket === ''
                    ? $this->blockParser->getCollapsedReference($ref)
                    : $this->blockParser->getReference($ref);
                if ($refDef !== null) {
                    // Track reference usage for validation
                    $this->blockParser->markReferenceUsed($ref, $this->currentLine);

                    $link = new Link($refDef->url, $refDef->title);
                    $this->documentHasLink = true;
                    // Store reference info for round-trip support
                    $link->setReferenceLabel($originalRefBracket === '' ? '' : $ref);
                    $this->parseInlines($link, $linkText);

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

                    return [
                        'node' => $link,
                        'pos' => $endPos,
                    ];
                }

                // Reference not found - leave the whole [text][ref] literal.
                $this->blockParser->addUndefinedReferenceWarning($ref, $this->currentLine, $pos + 1);
                $endPos = $refEnd + 1;

                return [
                    'node' => new RawText(substr($text, $pos, $endPos - $pos)),
                    'pos' => $endPos,
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
                if ($this->isValidAttrPayload($attrStr)) {
                    $span = new Span();
                    // The gating block is valid (real attributes or an
                    // empty/whitespace-only block). Apply and consume it here,
                    // then absorb any further consecutive attribute blocks
                    // (those stop at the first block that yields no attribute,
                    // leaving it literal -- e.g. `[x]{.a}{???}`).
                    $this->applyAttributesToNode($span, $attrStr);
                    $endPos = $this->applyConsecutiveAttributes($span, $text, $attrEnd + 1);
                    $this->parseInlines($span, $linkText);

                    return [
                        'node' => $span,
                        'pos' => $endPos,
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
        $length = strlen($text);
        if ($openPos >= $length || $text[$openPos] !== '[') {
            return null;
        }

        $bracketDepth = 1;
        $pos = $openPos + 1;
        while ($pos < $length) {
            if ($text[$pos] === '`') {
                $codeEnd = $this->findCodeSpanEnd($text, $pos);
                if ($codeEnd === null) {
                    return null;
                }
                $pos = $codeEnd;

                continue;
            }

            if ($text[$pos] === '[') {
                $bracketDepth++;

                // DoS guard: a run of openers like `[[[…[x]()]()…]()` makes the
                // main loop call this scan at every `[`, and each scan walked
                // the whole tail -> O(n^2) (an ~8 KB input timed out). Beyond a
                // bracket nesting far deeper than any real document we bail with
                // null (the run renders literally), bounding each scan to
                // O(MAX_BRACKET_NESTING) and the loop to O(n). Mirrors the inline
                // recursion cap (MAX_INLINE_DEPTH); the corpus has nothing near
                // this depth, so output is byte-identical.
                if ($bracketDepth > self::MAX_BRACKET_NESTING) {
                    return null;
                }
            } elseif ($text[$pos] === ']') {
                $bracketDepth--;
            } elseif ($text[$pos] === '\\' && $pos + 1 < $length) {
                $pos += 2;

                continue;
            }

            if ($bracketDepth === 0) {
                return $pos;
            }

            $pos++;
        }

        return null;
    }

    /**
     * @return array{node: \Carve\Node\Inline\Image, pos: int}|null
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

        // Extract alt text from link children
        $alt = $this->extractText($link);

        $image = new Image($link->getDestination() ?? '', $alt, $link->getTitle());

        // Transfer reference label for round-trip support
        if ($link->getReferenceLabel() !== null) {
            $image->setReferenceLabel($link->getReferenceLabel());
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

    protected function extractText(Node $node): string
    {
        $text = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Text) {
                $text .= $child->getContent();
            } else {
                $text .= $this->extractText($child);
            }
        }

        return $text;
    }

    /**
     * @return array{node: \Carve\Node\Inline\Link, pos: int}|null
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
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:[^\s<>"\\\\`{}|^]+$/', $content)) {
            $link = new Link($content);
            $link->setAutolink(true);
            $link->appendChild(new Text($content));
            $this->documentHasLink = true;

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
            $link->appendChild(new Text($content));
            $this->documentHasLink = true;

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
     * Parse delimited inline elements like _emphasis_ or *strong*
     *
     * @param string $delimiter
     * @param int $pos
     * @param string $text
     * @param class-string<\Carve\Node\Node> $nodeClass
     *
     * @return array{node: \Carve\Node\Node, pos: int}|null
     */
    protected function parseDelimited(string $text, int $pos, string $delimiter, string $nodeClass): ?array
    {
        $length = strlen($text);

        // Check if this can be an opener (not preceded by whitespace for closer detection)
        $prevChar = $pos > 0 ? $text[$pos - 1] : ' ';
        $nextChar = $text[$pos + 1] ?? ' ';

        // Can't open if followed by whitespace
        if (ctype_space($nextChar)) {
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
        $searchPos = $pos + 1;
        while ($searchPos < $length) {
            $char = $text[$searchPos];

            // Skip over attribute blocks {....} respecting quotes
            if ($char === '{') {
                $attrEnd = $this->findAttributeEnd($text, $searchPos);
                if ($attrEnd !== null) {
                    $searchPos = $attrEnd + 1;

                    continue;
                }
            }

            // Skip over code spans `...`
            if ($char === '`') {
                $codeEnd = $this->findCodeSpanEnd($text, $searchPos);
                if ($codeEnd !== null) {
                    $searchPos = $codeEnd;

                    continue;
                }

                // Unclosed backtick run: opaque to the end of the block, so no
                // closer can follow it and this delimiter cannot form emphasis.
                return null;
            }

            // Skip over autolinks <...>
            if ($char === '<') {
                $autolinkEnd = $this->findAutolinkEnd($text, $searchPos);
                if ($autolinkEnd !== null) {
                    $searchPos = $autolinkEnd;

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

                    continue;
                }
            }

            // Skip escape sequences
            if ($char === '\\' && $searchPos + 1 < $length) {
                $searchPos += 2;

                continue;
            }

            // Check for closing delimiter
            if ($char === $delimiter) {
                // Check if this can be a closer (not preceded by whitespace)
                $beforeClose = $searchPos > 0 ? $text[$searchPos - 1] : ' ';
                if (!ctype_space($beforeClose)) {
                    // A braced closer (like _} or *}) can only close a braced opener
                    // Since we're looking for a non-braced closer, skip if followed by }
                    $afterClose = $text[$searchPos + 1] ?? '';
                    if ($afterClose === '}') {
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
                    $this->parseInlines($node, $content);

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

        return null;
    }

    /**
     * Parse the combined bold-italic span /*text*\/, producing a Strong
     * node wrapping an Emphasis node.
     *
     * @param string $text
     * @param int $pos Position of the opening '/'
     *
     * @return array{node: \Carve\Node\Node, pos: int}|null
     */
    protected function parseBoldItalic(string $text, int $pos): ?array
    {
        $length = strlen($text);
        $start = $pos + 2; // skip '/*'

        if ($start >= $length || ctype_space($text[$start])) {
            return null;
        }

        $searchPos = $start;
        while ($searchPos + 1 < $length) {
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
                if ($content === '' || ctype_space($content[strlen($content) - 1])) {
                    $searchPos++;

                    continue;
                }

                $emphasis = new Emphasis();
                $this->parseInlines($emphasis, $content);
                $strong = new Strong();
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
     * Editorial comment {# ... #} -> <span class="critic-comment">…</span>.
     * Content is literal (spaces preserved), matching carve-js.
     *
     * @return array{node: \Carve\Node\Node, pos: int}|null
     */
    protected function parseEditorialComment(string $text, int $pos): ?array
    {
        if (substr($text, $pos, 2) !== '{#') {
            return null;
        }
        $close = strpos($text, '#}', $pos + 2);
        if ($close === false) {
            return null;
        }
        $span = new Span();
        $span->addClass('critic-comment');
        $span->appendChild(new Text(substr($text, $pos + 2, $close - $pos - 2)));

        return ['node' => $span, 'pos' => $close + 2];
    }

    /**
     * Parse braced inline syntax: {+insert+}, {-delete-},
     * forced delimiter spans, {~old~>new~} substitution, {'} and {"}.
     *
     * @return array{node: \Carve\Node\Node, pos: int}|array{nodes: list<\Carve\Node\Node>, pos: int}|null
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
        if ($marker === '~') {
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

        // Find closing: marker}
        // For braced syntax, we allow spaces inside (unlike bare delimiters)
        $searchPos = $pos + 2;
        while ($searchPos < $length - 1) {
            if ($text[$searchPos] === $marker && $text[$searchPos + 1] === '}') {
                $content = substr($text, $pos + 2, $searchPos - $pos - 2);
                $node = new $nodeClass();
                $this->parseInlines($node, $content);

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
            && ctype_space($prevConverted)
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
        // before this standalone-attribute handler runs. (A block that becomes
        // empty only AFTER comment removal, e.g. `{% note %}`, is still a
        // consumed comment -- handled below.)
        if (trim($attrStr) === '') {
            return null;
        }

        // Check if this looks like valid attributes (starts with ., #, % comment, or key=)
        // Exclude _ * = + - ~ ^ which are braced inline markers
        if (!preg_match('/^[.#a-zA-Z%]/', $attrStr)) {
            return null;
        }

        // Remove comments from attributes: % ... % or % to end
        $attrStr = $this->removeAttributeComments($attrStr);

        // A comment-only block (`{% note %}`) reduces to empty here: consume it
        // (the comment vanishes) rather than leaving it literal.
        if (trim($attrStr) === '') {
            return [
                'textBuffer' => $textBuffer,
                'pos' => $attrEnd + 1,
            ];
        }

        // The block must yield a valid attribute, else it is not an attribute
        // block (§14): a digit-first name (`.123`, `#1`, `2=v`) or other
        // unrecognized content makes the whole `{...}` stay literal. Decline
        // so the caller emits `{` literally and re-parses the content.
        if (!$this->isValidAttrPayload($attrStr)) {
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
    public function isValidAttrPayload(string $attrStr): bool
    {
        // Strip every RECOGNIZED token; if anything non-whitespace remains the
        // block is invalid and stays literal (§14). A name (key, class, id)
        // is a grammar identifier (letter/`_` first), so a digit-first or
        // hyphen-first name is NOT recognized -- one bad name invalidates the
        // WHOLE block, even mixed with valid ones, matching carve-js.
        // Booleans, colon-bearing keys, and an invalid unquoted VALUE (which
        // is tolerated and skipped) all stay accepted.
        $rest = $attrStr;
        // Quoted key=values first, so `%`, dots and braces inside quotes are
        // protected from the comment stripper and the shorthand patterns.
        $rest = preg_replace(
            '/(?:(?<=\s)|^)[a-zA-Z_][a-zA-Z0-9_:-]*=(?:"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"|\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\')/',
            ' ',
            $rest,
        ) ?? $rest;
        $rest = $this->removeAttributeComments($rest);
        if (trim($rest) === '') {
            return true;
        }
        $patterns = [
            // unquoted key=value (the key is an identifier; the value is
            // tolerant like carve-js's `\S+`, so an invalid value is skipped)
            '/(?:(?<=\s)|^)[a-zA-Z_][a-zA-Z0-9_:-]*=[^\s}]+/',
            '/\.[a-zA-Z_][a-zA-Z0-9_:-]*/',
            '/#[a-zA-Z_][a-zA-Z0-9_:-]*/',
            '/(?:(?<=\s)|^)[a-zA-Z][a-zA-Z0-9_-]*(?=\s|$)/',
            '/\s+/',
        ];
        foreach ($patterns as $pattern) {
            $rest = preg_replace($pattern, ' ', $rest) ?? $rest;
        }

        return trim($rest) === '';
    }

    /**
     * Find the end of an attribute block, handling quoted strings
     */
    protected function findAttributeEnd(string $text, int $pos): ?int
    {
        $length = strlen($text);
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
        $length = strlen($text);

        // Count opening backticks
        $openBackticks = 0;
        while ($pos + $openBackticks < $length && $text[$pos + $openBackticks] === '`') {
            $openBackticks++;
        }

        if ($openBackticks === 0) {
            return null;
        }

        $contentStart = $pos + $openBackticks;

        // Find matching closing backticks
        $closingPattern = str_repeat('`', $openBackticks);
        $searchPos = $contentStart;

        while ($searchPos < $length) {
            $closePos = strpos($text, $closingPattern, $searchPos);
            if ($closePos === false) {
                return null;
            }

            // Make sure we have exactly the right number of backticks (not more)
            $afterClose = $closePos + $openBackticks;
            if ($afterClose >= $length || $text[$afterClose] !== '`') {
                return $afterClose;
            }

            $searchPos = $closePos + 1;
        }

        return null;
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

        $i = $pos + 1;

        while ($i < $length) {
            $char = $text[$i];
            if ($char === '\\' && $i + 1 < $length) {
                // Skip escaped character
                $i++;
            }
            if ($char === ')') {
                return $i + 1;
            }
            $i++;
        }

        return null;
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
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:[^\s<>"\\\\`{}|^]+$/', $content)) {
            return $end + 1;
        }

        // Check if it's a valid email autolink (same email_char body as parseAutolink).
        if (preg_match('/^[A-Za-z0-9._+-]+@[A-Za-z0-9._+-]+\.[A-Za-z]+$/', $content)) {
            return $end + 1;
        }

        return null;
    }

    /**
     * Remove comments from attribute string: % ... % or % to end
     */
    protected function removeAttributeComments(string $attrStr): string
    {
        // Remove % ... % comments
        $result = preg_replace('/%[^%]*%/', '', $attrStr);
        if ($result === null) {
            return $attrStr;
        }

        // Remove % to end of string comments
        $percentPos = strpos($result, '%');
        if ($percentPos !== false) {
            $result = substr($result, 0, $percentPos);
        }

        return $result;
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
            // A `{...}` that yields no real attribute is literal text (PART 9
            // §15), not an empty attribute block to consume. Stop here and
            // leave it in the stream (so e.g. `{=hl=}`, `{ }`, `{???}` after a
            // node render literally instead of being silently dropped). The
            // inline-span branch in parseLink() handles the leading
            // empty/whitespace block explicitly before delegating here.
            if (AttributeParser::parse($attrStr) === []) {
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
     * @return array{node: \Carve\Node\Inline\InlineFootnote, pos: int}|null
     */
    protected function parseInlineFootnote(string $text, int $pos): ?array
    {
        $length = strlen($text);
        if ($pos + 1 >= $length || $text[$pos] !== '^' || $text[$pos + 1] !== '[') {
            return null;
        }

        if ($pos > 0 && $text[$pos - 1] === '^') {
            return null;
        }

        $close = $this->findBalancedBracketEnd($text, $pos + 1);
        if ($close === null) {
            return null;
        }

        $content = substr($text, $pos + 2, $close - $pos - 2);
        if (trim($content) === '') {
            return null;
        }

        $node = new InlineFootnote();
        $this->parseInlines($node, $content, false);

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
     * @return array{node: \Carve\Node\Inline\FootnoteRef, pos: int}|null
     */
    protected function parseFootnoteRef(string $text, int $pos): ?array
    {
        // Match [^label] - \G anchors at offset position, avoiding extra strpos check
        if (!preg_match('/\G\[\^([^\]]+)\]/', $text, $matches, 0, $pos)) {
            return null;
        }

        $label = $matches[1];

        // Warn if footnote is not defined
        if (!$this->blockParser->hasFootnote($label)) {
            $this->blockParser->addUndefinedFootnoteWarning($label, $this->currentLine, $pos + 1);

            return null;
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
     * @return array{node: \Carve\Node\Inline\Math, pos: int}|null
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

        // Find closing backticks
        $closingBackticks = str_repeat('`', $backtickCount);
        $closePos = strpos($text, $closingBackticks, $contentStart);

        if ($closePos === false) {
            return null;
        }

        $content = substr($text, $contentStart, $closePos - $contentStart);
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
     * Parse an inline extension :type[content].
     *
     * @return array{node: \Carve\Node\Node, pos: int}|null
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
        $this->parseInlines($node, $matches[2]);

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
     * @return array{node: \Carve\Node\Inline\Symbol, pos: int}|null
     */
    protected function parseSymbol(string $text, int $pos): ?array
    {
        // Match :word: - \G anchors at offset position, avoiding extra strpos check
        if (!preg_match('/\G:([a-zA-Z_][a-zA-Z0-9_-]*):/', $text, $matches, 0, $pos)) {
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
    protected function normalizeReferenceLabel(string $label): string
    {
        // Strip inline formatting markers: _ * ~ ^ + = { } ` [ ]
        // But keep the content between them
        $label = preg_replace('/[_*~^+={}`\[\]]/', '', $label) ?? $label;

        // Normalize whitespace: collapse multiple spaces/newlines to single space
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;

        // Trim
        return trim($label);
    }
}
