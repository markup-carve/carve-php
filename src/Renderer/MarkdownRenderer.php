<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use Closure;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Exception\RenderDepthExceededException;
use MarkupCarve\Carve\Node\Block\AbbreviationDefinition;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Block\CitationDefinition;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\DefinitionDescription;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\DefinitionTerm;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\FigureGroup;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\LineBlock;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\ThematicBreak;
use MarkupCarve\Carve\Node\Document;
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
use MarkupCarve\Carve\Node\Inline\InlineFootnote;
use MarkupCarve\Carve\Node\Inline\InlineNode;
use MarkupCarve\Carve\Node\Inline\Insert;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\LiteralInline;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Inline\RawInline;
use MarkupCarve\Carve\Node\Inline\RawText;
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
use MarkupCarve\Carve\Node\Inline\UnresolvedReference;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\Utility\AbbreviationBudgetTrait;
use MarkupCarve\Carve\Renderer\Utility\DerivedLabelTrait;
use MarkupCarve\Carve\Renderer\Utility\DocumentSentinels;
use MarkupCarve\Carve\Renderer\Utility\EventDispatcherTrait;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * Renders AST to Markdown (CommonMark compatible where possible)
 */
class MarkdownRenderer implements RendererInterface, RenderLossAwareRendererInterface
{
    use RenderLossCollectorTrait;
    use AbbreviationBudgetTrait;
    use DerivedLabelTrait;

    use EventDispatcherTrait;

    /**
     * Presentation renderers emit the resolved glyph; the Carve renderer emits
     * the author's source instead, so `fmt` reproduces the input.
     */
    protected function renderSmartPunctuation(SmartPunctuation $node): string
    {
        if ($this->smartTypography === SmartTypographyMode::Source) {
            return $node->getContent();
        }

        return $node->getGlyph() ?? SmartPunctuation::GLYPHS[$node->getKind()] ?? $node->getContent();
    }

    /**
     * The characters PART 11 §8a M1b decides by ADJACENCY on the LINE, in the
     * order the sentinel run is assigned to them.
     *
     * THE HASH IS NOT HERE, and that is M1f rather than an omission. A `#` does
     * not collide with a delimiter of its own character; it opens an ATX
     * heading at a line's content position and is inert everywhere else, which
     * is the test §8b M2b already applies on the authored side.
     *
     * THE ASTERISK IS NOT HERE, and that is M1a rather than an omission. This
     * writer spells emphasis with `*`, so a literal asterisk is not a character
     * that MIGHT meet markup on the line - it is the character the line's markup
     * is made of. `*\*\**` unescaped to `****`, and a CommonMark reader
     * publishes emphasis-containing-two-asterisks as a thematic break.
     *
     * @var list<string>
     */
    private const NARROWED_CHARACTERS = ['_', '['];

    /**
     * PART 11 §8b M2a: characters this target's readers never read as markup,
     * at ANY position on the line.
     *
     * An `escaped_text` node holding one of these is emitted BARE. They are
     * Carve's own delimiters and Markdown has no reading for them, so the
     * escape protects nothing and lands inside an identifier, which is the cost
     * §2 calls a defect rather than a safe default.
     *
     * `~` is NOT here: GFM reads `~x~` as strikethrough. Nor are the
     * smart-punctuation triggers `"`, `'`, `-` and `.`, which §8b keeps
     * whatever their position, because a processor with substitution on
     * rewrites the TEXT rather than reading markup.
     *
     * @var list<string>
     */
    private const AUTHORED_INERT = ['{', '}', '^', ',', '%', ':', '/', '@'];

    /**
     * PART 11 §8a M1f and §8b M2b: read as markup only at a line's CONTENT
     * POSITION.
     *
     * `#` opens an ATX heading there and is inert everywhere else, so the
     * decision is a property of the line and takes a sentinel like M1b's. One
     * family serves both clauses: they ask the same positional question, so a
     * `#` from text and one from an `escaped_text` node are decided alike.
     *
     * @var list<string>
     */
    private const AUTHORED_POSITIONAL = ['#'];

    /**
     * The same characters once §8b M2b HAS decided to keep the escape.
     *
     * @var list<string>
     */
    private const AUTHORED_DECIDED = ['#'];

    /**
     * The first code point of the run picked for the narrowed-escape sentinels.
     *
     * @var int
     */
    protected const NARROWED_SENTINEL_FIRST = 0xE004;

    /**
     * Sentinels standing in for the escapes PART 11 §8a decides on the LINE,
     * one per narrowed character, CHOSEN PER DOCUMENT from code points the
     * document does not contain.
     *
     * @var array<string, string>
     */
    protected array $narrowedSentinels = [
        '_' => "\u{E004}",
        '[' => "\u{E005}",
    ];

    /**
     * Sentinels standing in for the AUTHORED escapes PART 11 §8b M2b decides on
     * the line, one per positional character, replaced per document alongside
     * the M1b run above.
     *
     * A separate map rather than a wider one, because the two families are
     * decided by DIFFERENT tests: M1b asks about an adjacent delimiter of the
     * same character, M2b asks where on the line the character stands.
     *
     * @var array<string, string>
     */
    protected array $authoredSentinels = [
        '#' => "\u{E006}",
    ];

    /**
     * Sentinels standing in for an authored escape §8b M2b has decided to KEEP,
     * one per positional character, picked in the same run as the two above.
     *
     * @var array<string, string>
     */
    protected array $authoredKeptSentinels = [
        '#' => "\u{E007}",
    ];

    /**
     * The picked run as a PCRE class, for the final resolve.
     *
     * The run is contiguous by construction, so the class is its two ends. Built
     * with the run rather than written out, so a fourth narrowed character
     * cannot be added above without the class moving with it.
     *
     * @var string
     */
    protected string $narrowedSentinelClass = '[\x{E004}-\x{E007}]';

    protected int $listDepth = 0;

    /**
     * Hashes emitted since the enclosing container started, so a container that
     * emitted none skips the position pass instead of re-scanning its subtree
     * once per enclosing level - the shape carve-php#1142 fixed.
     */
    protected int $authoredHashes = 0;

    protected bool $inBlockQuote = false;

    protected SoftBreakMode $softBreakMode = SoftBreakMode::Newline;

    protected HeadingIdTracker $headingIdTracker;

    protected int $renderDepth = 0;

    /**
     * Resolved ids of headings that are the target of a `</#id>` cross-reference.
     * Such headings emit a `{#id}` attribute (pandoc/kramdown convention) so the
     * `[label](#id)` link they are referenced by resolves to a real anchor.
     *
     * @var array<string, true>
     */
    protected array $referencedHeadingIds = [];

    /**
     * Resolved ids of ALL headings (figures/tables are excluded). Lets a
     * `</#id>` decide whether its target can carry a markdown anchor.
     *
     * @var array<string, true>
     */
    protected array $headingIds = [];

    protected SmartTypographyMode $smartTypography = SmartTypographyMode::Glyph;

    protected AttributeFallback $attributeFallback = AttributeFallback::Drop;

    /**
     * Lazily built HTML renderer used ONLY to serialize attributes under
     * AttributeFallback::Html, so the raw HTML this target emits is validated and
     * escaped by the same code as the HTML target rather than by a second copy.
     */
    private ?HtmlRenderer $attributeSerializer = null;

    public function __construct()
    {
        $this->headingIdTracker = new HeadingIdTracker();
    }

    /**
     * Render smart typography as its glyph (the default) or as the source run.
     *
     * Source mode is for output a machine reads rather than a person: the
     * ellipsis, dashes and curly quotes are a presentation choice, and a
     * consumer searching the text for what the author wrote does not want them.
     * It only affects smart typography - escaping is a separate concern and is
     * unchanged.
     */
    public function setSmartTypography(SmartTypographyMode $mode): self
    {
        $this->smartTypography = $mode;

        return $this;
    }

    /**
     * The configured smart-typography mode.
     *
     * Read by {@see \MarkupCarve\Carve\Extension\BeforeRenderContext} so a `beforeRender` hook on this target sees what
     * the caller configured rather than a default. Smart typography is not an
     * HTML-only option - it reaches every renderer - so every renderer answers
     * for it.
     */
    public function getSmartTypography(): SmartTypographyMode
    {
        return $this->smartTypography;
    }

    /**
     * Set how soft breaks are rendered.
     */
    public function setSoftBreakMode(SoftBreakMode $mode): self
    {
        $this->softBreakMode = $mode;

        return $this;
    }

    /**
     * Keep attributes Markdown cannot spell as raw HTML, or drop them (the
     * default).
     *
     * Markdown has no block container and no attribute syntax on an image, so
     * `{#id .class data-*}` has nowhere to go and is dropped - right for
     * human-facing export, data loss for a consumer using Markdown as an
     * interchange format. `AttributeFallback::Html` degrades those two to raw
     * HTML instead, the way an inline mark already degrades to `<mark>`.
     * Everything else is unchanged, and `Drop` output is byte-identical to
     * before.
     */
    public function setAttributeFallback(AttributeFallback $mode): self
    {
        $this->attributeFallback = $mode;

        return $this;
    }

    /**
     * Get the current soft break mode.
     */
    public function getSoftBreakMode(): SoftBreakMode
    {
        return $this->softBreakMode;
    }

    /**
     * Every abbreviation definition the author wrote, as source lines.
     */

    /**
     * Definitions the document holds only as map entries (the API path, see
     * Document::getAbbreviationDefinitionsNotInTree). They have no source line
     * of their own, so they are written together at the end the document flag
     * names.
     */
    protected function renderResidualAbbreviationDefinitions(Document $document): string
    {
        $lines = [];
        foreach ($document->getAbbreviationDefinitionsNotInTree() as $definition) {
            $lines[] = '*[' . $this->escapeHtml($this->stripControls($definition['abbr'])) . ']: '
                . $this->escapeHtml($this->stripControls($definition['expansion']));
        }

        return $lines === [] ? '' : implode("\n\n", $lines) . "\n";
    }

    protected function renderAbbreviationDefinition(AbbreviationDefinition $child): string
    {
        // The definition line goes through escapeHtml() for the same reason the
        // `<abbr>` built from it does: an expansion is author content, and this
        // target's contract is that embedded HTML cannot become live markup
        // downstream. Writing the occurrence escaped and the definition raw made
        // one output disagree with itself (carve-php#1063).
        return '*[' . $this->escapeHtml($this->stripControls($child->getAbbr())) . ']: '
            . $this->escapeHtml($this->stripControls($child->getExpansion())) . "\n\n";
    }

    public function render(Document $document): string
    {
        $this->pickNarrowedSentinels($document);
        $this->headingIdTracker->reset();
        $this->resetExpansionBudgetForDocument($document);
        (new CrossReferenceResolver())->resolve($document, $this->headingIdTracker);

        // Collect every heading's resolved id and the set of ids that a `</#id>`
        // points at, so a heading that IS a crossref target can emit `{#id}` and
        // its reference can render a working `[label](#id)` link.
        $this->headingIds = [];
        $referencedIds = [];
        $this->collectHeadingAndRefIds($document, $referencedIds);
        $this->referencedHeadingIds = array_intersect_key($this->headingIds, $referencedIds);

        // The definition renders WHERE IT WAS WRITTEN, from its node, because
        // the dispatch above has an arm for it. This used to place the whole set
        // at one end of the body, chosen by `hasAbbreviationsBeforeBody()` - two
        // positions, which is one fewer than a document can express, so a
        // definition authored BETWEEN two blocks moved to an end and
        // `parse(fmt(x)) != parse(x)`. carve-js and carve-rs both keep it in
        // place, and this node exists precisely so this renderer can too
        // (carve-php#708).
        $markdown = $this->renderChildren($document);
        $residual = $this->renderResidualAbbreviationDefinitions($document);
        if ($residual !== '') {
            $markdown = $document->hasAbbreviationsBeforeBody()
                ? $residual . "\n" . $markdown
                : $markdown . "\n" . $residual;
        }

        // Normalize multiple blank lines
        $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown;

        $markdown = trim($markdown, StringUtil::TRIMMABLE_WHITESPACE) . "\n";

        $markdown = $this->resolveNarrowedEscapes($markdown);

        // The internal non-breaking-space placeholder (U+E000) becomes a literal
        // non-breaking space (U+00A0). Markdown is a re-parseable round-trip
        // format, so unlike the display renderers it keeps the real nbsp: that
        // survives a re-render as `&nbsp;` and is never mistaken for an indented
        // code-block prefix the way ordinary leading spaces would be. Done after
        // trimming so placeholder-derived leading indentation survives.
        return str_replace("\u{E000}", "\u{00A0}", $markdown);
    }

    /**
     * An escape the author wrote, kept as an escape (PART 11 §8 M2).
     *
     * NO SENTINEL HERE, and §8a says why. M1 - and therefore M1b - governs a
     * character that reached this writer inside a TEXT node, one the Carve
     * grammar did not read as an opener and the author did not mark. This is the
     * other case: the author said which reading they meant, M2 gives it back
     * whatever the character, and the line test never sees it.
     *
     * The underscore used to take the sentinel here and could then lose its
     * backslash to the old intraword rule, which was the line test deciding a
     * node M1 never governed.
     */
    protected function renderEscapedText(EscapedText $node): string
    {
        $content = $this->stripControls($node->getContent());

        // PART 11 §8b narrows M2 on the same finding §8a narrowed M1 on. The
        // character still decides, but now it decides whether the escape
        // protects anything ON THIS TARGET rather than whether the author wrote
        // it. Everything not named by §8b keeps M2 as written, including the
        // `[` the §8a rationale rests on and the smart-punctuation triggers §8
        // states M2 for.
        if (in_array($content, self::AUTHORED_INERT, true)) {
            return $content;
        }

        if (isset($this->authoredSentinels[$content])) {
            return $this->positionalHash($content);
        }

        return '\\' . $content;
    }

    /**
     * Emit a `#` as the undecided carrier, counted (PART 11 §8a M1f, §8b M2b).
     */
    protected function positionalHash(string $character): string
    {
        $this->authoredHashes++;

        return $this->authoredSentinels[$character];
    }

    /**
     * Choose sentinels this document does not contain.
     *
     * Called from render() only. The run has to be fixed before the first node
     * is rendered, because escapeText() inserts sentinels while the document is
     * still being assembled and resolveNarrowedEscapes() reads them back at the
     * end - both passes have to agree on which characters they are.
     */
    protected function pickNarrowedSentinels(Document $document): void
    {
        $narrowed = count(self::NARROWED_CHARACTERS);
        $positional = count(self::AUTHORED_POSITIONAL);
        $run = DocumentSentinels::pick(
            DocumentSentinels::collectStrings($document),
            $narrowed + $positional + count(self::AUTHORED_DECIDED),
            self::NARROWED_SENTINEL_FIRST,
        );

        // ONE RUN, TWO FAMILIES. Picked together so the collision search runs
        // once and the two families are guaranteed contiguous, which is what
        // lets a single character class find every candidate in one pass.
        $this->narrowedSentinels = array_combine(
            self::NARROWED_CHARACTERS,
            array_slice($run, 0, $narrowed),
        );
        $this->authoredSentinels = array_combine(
            self::AUTHORED_POSITIONAL,
            array_slice($run, $narrowed, $positional),
        );
        $this->authoredKeptSentinels = array_combine(
            self::AUTHORED_DECIDED,
            array_slice($run, $narrowed + $positional),
        );
        $this->authoredHashes = 0;
        $this->narrowedSentinelClass = '[' . $run[0] . '-' . $run[count($run) - 1] . ']';
    }

    /**
     * Resolve the narrowed escapes: PART 11 §8a, M1b.
     */
    protected function resolveNarrowedEscapes(string $markdown): string
    {
        $pattern = '/' . $this->narrowedSentinelClass . '/u';

        if (preg_match_all($pattern, $markdown, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return $markdown;
        }

        $character = array_flip($this->narrowedSentinels);
        $authored = array_flip($this->authoredSentinels);
        $kept = array_flip($this->authoredKeptSentinels);
        $character += $authored + $kept;

        // THE LINE AS IT READS IF NOTHING IS ESCAPED. Every candidate is
        // resolved to its BARE character first, so a neighbour that is itself a
        // candidate is compared as the character it stands for. Deciding
        // candidates left to right against a half-rewritten line would make the
        // answer depend on the order they were visited: in `a __b` the second
        // underscore would see a backslash the first one had just put there.
        $line = (string)preg_replace_callback(
            $pattern,
            static fn (array $m): string => $character[$m[0]],
            $markdown,
        );

        // OFFSETS DO NOT CARRY ACROSS, which is the trap in spelling this on
        // bytes. Each sentinel is a U+E00x code point, three bytes in UTF-8,
        // and it stands for a one-byte character - so every sentinel before a
        // candidate shifts its position in `$line` two bytes left. carve-js can
        // reuse the offset directly because its sentinel is one UTF-16 unit
        // exactly like the character it replaces; here it cannot.
        $pairs = str_contains($line, '_') ? $this->pairableUnderscoresPerBlock($line) : [];

        $out = '';
        $read = 0;
        foreach ($matches[0] as $index => [$sentinel, $offset]) {
            $offset = (int)$offset;
            $char = $character[$sentinel];

            $at = $offset - 2 * $index;
            // TWO FAMILIES, TWO TESTS, AND A THIRD CASE ALREADY SETTLED. M1b
            // asks whether a delimiter of the same character stands beside the
            // candidate. M2b asks WHERE ON THE LINE the candidate stands, and
            // this is the finished document, so the answer it gets here is the
            // answer for a line NO CONTAINER ENCLOSES - the only kind that
            // reaches it undecided. A line inside a container had its position
            // settled where the writer prefixed it, while the prefix was still
            // separable from the content (markup-carve/carve#1330), and arrives
            // carrying that answer.
            $keep = isset($kept[$sentinel])
                || (isset($authored[$sentinel])
                    ? $this->opensAnAtxHeading($line, $at)
                    : $this->adjacentToLiveDelimiter($line, $at, $char)
                        || ($char === '_' && isset($pairs[$at])));

            $out .= substr($markdown, $read, $offset - $read);
            $out .= $keep ? '\\' . $char : $char;
            $read = $offset + strlen($sentinel);
        }

        return $out . substr($markdown, $read);
    }

    /**
     * The finished content of a container, trimmed and with PART 11 §8b M2b
     * ANSWERED ON IT, ready for the caller to put its prefix in front.
     *
     * @param \Closure $render Renders the container's children.
     *
     * @return string
     */
    protected function containerContent(Closure $render): string
    {
        $before = $this->authoredHashes;
        $content = trim($render(), StringUtil::TRIMMABLE_WHITESPACE);
        if ($this->authoredHashes === $before) {
            return $content;
        }
        $this->authoredHashes = $before;

        return $this->decideAuthoredHashes($content);
    }

    /**
     * Answer §8b M2b for every authored hash in one container's content, on the
     * lines that container writes.
     *
     * Deriving the position from the finished document would mean parsing the
     * prefixes back off it, and the §10 alignment case cannot be recovered that
     * way at all: a continuation line under `10. ` carries four spaces of pad,
     * which reads as an over-indent to anything that does not already know the
     * marker's width. §10 refuses to reason about the content alone for that
     * exact reason. So it is not derived - it is answered where the writer has
     * it, and everything a container adds afterwards is a prefix by
     * construction, without any of them being named.
     *
     * THE NARROWING IS UNTOUCHED, which is the half a correction like this
     * loses first. Standing behind a prefix is not enough on its own: a hash
     * mid-line still drops its escape inside a container, and one at the
     * content position whose run is closed by a letter drops it too.
     *
     * @param string $text One container's rendered content.
     *
     * @return string
     */
    protected function decideAuthoredHashes(string $text): string
    {
        $pattern = '/' . $this->narrowedSentinelClass . '/u';

        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return $text;
        }

        $undecided = array_flip($this->authoredSentinels);
        $character = array_flip($this->narrowedSentinels)
            + $undecided
            + array_flip($this->authoredKeptSentinels);

        $line = (string)preg_replace_callback(
            $pattern,
            static fn (array $m): string => $character[$m[0]],
            $text,
        );

        // OFFSETS DO NOT CARRY ACROSS, the same trap resolveNarrowedEscapes
        // spells out: every sentinel is a three-byte code point standing for a
        // one-byte character, so each one before a candidate shifts it two
        // bytes left in `$line`. The index counts EVERY sentinel, decided or
        // not, because every one of them is three bytes wide.
        $out = '';
        $read = 0;
        foreach ($matches[0] as $index => [$sentinel, $offset]) {
            if (!isset($undecided[$sentinel])) {
                continue;
            }

            $offset = (int)$offset;
            $char = $undecided[$sentinel];
            $at = $offset - 2 * $index;

            $out .= substr($text, $read, $offset - $read);
            $out .= $this->opensAnAtxHeading($line, $at)
                ? $this->authoredKeptSentinels[$char]
                : $char;
            $read = $offset + strlen($sentinel);
        }

        return $out . substr($text, $read);
    }

    /**
     * Whether the `#` at `$offset` would open an ATX heading (§8b M2b).
     *
     * `$line` is the assembled output with every candidate resolved to its BARE
     * character, the same view M1b decides on.
     *
     * Three conditions, all of them CommonMark's: the character stands at the
     * line's content position, which admits up to three leading spaces; the run
     * of hashes starting there is one to six long; and the run is closed by a
     * space, a tab or the end of the line. `#tag`, `#123` and `#64748b` fail the
     * third even at a line's start, which is why the test is spelled on the run
     * rather than on the position alone.
     *
     * `$line` IS ONE CONTAINER'S CONTENT where a container encloses it, and the
     * whole document where none does. A line's content position is after its
     * container prefix, and the prefix is separable from the content only
     * before the writer joins them (markup-carve/carve#1330), so the caller
     * chooses the string and this decides on whatever it is handed.
     *
     * BOTH CONDITIONS ARE ANSWERED WITHOUT READING THE LINE (carve#1331). The
     * first spelling searched backward for the line's newline and counted the
     * whole run of hashes, so a candidate cost O(line) - and in this engine
     * that search does not merely read the prefix, `substr` ALLOCATES AND
     * COPIES it. A line of adjacent authored hashes is all candidates, so
     * 400,000 of them copied 240GB. Neither answer needs the line, because both
     * conditions are bounded:
     *
     * - At most three spaces may precede the character, so the walk back stops
     *   after four steps and the fourth decides. Anything else standing there
     *   means the content position is elsewhere on the line, whatever the rest
     *   of it holds.
     * - The run has to be six or shorter, so counting stops at seven. The
     *   seventh hash settles the question and the eight-thousandth cannot
     *   change it.
     *
     * BYTES THROUGHOUT, like the spelling it replaces. Every character it
     * compares is ASCII, and a UTF-8 continuation byte is never equal to one.
     */
    protected function opensAnAtxHeading(string $line, int $offset): bool
    {
        // The walk back over the indent, bounded at the four positions that can
        // decide it. `$i` lands on the first character of the run of spaces, so
        // the line must either start there or carry its newline just before it.
        $i = $offset;
        while ($i > 0 && $line[$i - 1] === ' ') {
            if ($offset - $i >= 3) {
                return false;
            }
            $i--;
        }

        if ($i > 0 && $line[$i - 1] !== "\n") {
            return false;
        }

        $run = 0;
        while ($run <= 6 && ($line[$offset + $run] ?? '') === '#') {
            $run++;
        }

        if ($run > 6) {
            return false;
        }

        $after = $line[$offset + $run] ?? "\n";

        return $after === ' ' || $after === "\t" || $after === "\n";
    }

    /**
     * Whether the candidate at `$offset` is adjacent to an unescaped delimiter
     * of the same character, on the line the writer is building (§8a M1b).
     *
     * `$line` is the assembled output with every candidate resolved to its BARE
     * character. "On the emitted line" needs no line splitting: a neighbour
     * across a newline IS a newline, which is never the same character.
     *
     * A neighbour BEFORE the candidate counts only if it is not itself behind a
     * backslash - the clause's "not behind a backslash" - so the run of
     * backslashes in front of it is counted and an odd run disqualifies it. A
     * neighbour AFTER never can be: the character in front of it is the
     * candidate itself.
     *
     * @param string $line
     * @param int $offset
     * @param string $char
     *
     * @return bool
     */
    protected function adjacentToLiveDelimiter(string $line, int $offset, string $char): bool
    {
        $width = strlen($char);

        if (substr($line, $offset + $width, $width) === $char) {
            return true;
        }
        if ($offset < $width || substr($line, $offset - $width, $width) !== $char) {
            return false;
        }

        $backslashes = 0;
        for ($i = $offset - $width - 1; $i >= 0 && $line[$i] === '\\'; $i--) {
            $backslashes++;
        }

        return $backslashes % 2 === 0;
    }

    /**
     * M1b's pair condition is asked over the inline content the underscore is
     * emitted in -- a paragraph, heading or table cell -- so the scan is taken
     * per block (markup-carve/carve#2046, markup-carve/carve-php#2009).
     *
     * @return array<int, bool>
     */
    protected function pairableUnderscoresPerBlock(string $line): array
    {
        $pairs = [];
        foreach ($this->inlineBlocks($line) as $block) {
            // The ranges are joined by one space, which is what a line or cell
            // boundary is to the reader, and the offsets are mapped back.
            $joined = '';
            $origin = [];
            foreach ($block as [$start, $end]) {
                if ($joined !== '') {
                    $joined .= ' ';
                    $origin[] = -1;
                }
                for ($i = $start; $i < $end; $i++) {
                    $joined .= $line[$i];
                    $origin[] = $i;
                }
            }
            foreach ($this->pairableUnderscores($joined) as $offset => $_) {
                if ($origin[$offset] >= 0) {
                    $pairs[$origin[$offset]] = true;
                }
            }
        }

        return $pairs;
    }

    /**
     * The emitted document cut into the blocks M1b reads, each as the content
     * ranges of its lines: a blank line ends a block, a new list item starts
     * one, and every table cell is one of its own.
     *
     * A HEADING NEEDS NO CASE OF ITS OWN HERE, which carve-rs's port of this
     * does carry. This writer separates a heading from the block below it with
     * a blank line in every container except a tight list item, where the item
     * marker is the separator -- so both rules above already cut there, and a
     * heading case would be a branch that cannot fire. Measured over the
     * 1707-document corpus and four inline matrices: removing it moves no byte.
     *
     * @return array<int, array<int, array{int, int}>>
     */
    protected function inlineBlocks(string $line): array
    {
        $blocks = [];
        $current = [];
        $length = strlen($line);
        $lineStart = 0;
        while ($lineStart <= $length) {
            $newline = strpos($line, "\n", $lineStart);
            $lineEnd = $newline === false ? $length : $newline;
            $content = min($this->contentPosition($line, $lineStart), $lineEnd);
            $body = substr($line, $content, $lineEnd - $content);
            if (trim($body, ' ') === '') {
                if ($current !== []) {
                    $blocks[] = $current;
                    $current = [];
                }
            } elseif ($body[0] === '|') {
                if ($current !== []) {
                    $blocks[] = $current;
                    $current = [];
                }
                $cell = $content + 1;
                for ($i = $cell; $i < $lineEnd; $i++) {
                    if ($line[$i] === '\\') {
                        $i++;

                        continue;
                    }
                    if ($line[$i] === '|') {
                        $blocks[] = [[$cell, $i]];
                        $cell = $i + 1;
                    }
                }
                if ($cell < $lineEnd) {
                    $blocks[] = [[$cell, $lineEnd]];
                }
            } elseif ($this->startsAListItem($line, $lineStart)) {
                if ($current !== []) {
                    $blocks[] = $current;
                }
                $current = [[$content, $lineEnd]];
            } else {
                $current[] = [$content, $lineEnd];
            }
            $lineStart = $lineEnd + 1;
        }
        if ($current !== []) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * Where a line's own content begins, past every container prefix in front.
     */
    protected function contentPosition(string $line, int $lineStart): int
    {
        $at = $lineStart;
        while (true) {
            while (($line[$at] ?? '') === ' ') {
                $at++;
            }
            $end = $this->containerPrefixEnd($line, $at);
            if ($end === null) {
                return $at;
            }
            $at = $end;
        }
    }

    /**
     * One container prefix at $at, and where it ends.
     *
     * @see self::contentPosition()
     */
    protected function containerPrefixEnd(string $line, int $at): ?int
    {
        $ch = $line[$at] ?? '';
        if ($ch === '') {
            return null;
        }

        // A quote marker takes ONE following space, the separator this writer
        // emits. A blank quote line is written bare (`>`), so it is optional.
        if ($ch === '>') {
            return ($line[$at + 1] ?? '') === ' ' ? $at + 2 : $at + 1;
        }

        // A bullet. The task box after it -- `- [ ] ` -- needs no case of its
        // own: it is bracketed by spaces, so no candidate can stand beside it,
        // and whether it counts as prefix or as content changes no answer.
        if (($ch === '-' || $ch === '*' || $ch === '+') && ($line[$at + 1] ?? '') === ' ') {
            return $at + 2;
        }

        // An ordered marker: digits, then the authored delimiter, then the
        // separator. A number no writer emits is not a marker, so the digit run
        // is bounded.
        if ($ch >= '0' && $ch <= '9') {
            $end = $at;
            while ($end - $at < 9 && ctype_digit($line[$end] ?? '')) {
                $end++;
            }
            $delimiter = $line[$end] ?? '';
            if (($delimiter === '.' || $delimiter === ')') && ($line[$end + 1] ?? '') === ' ') {
                return $end + 2;
            }

            return null;
        }

        // A footnote definition's label, which this writer emits as `[^id]: `.
        // An abbreviation definition (`*[X]: `) is NOT one: its body is not a
        // container.
        if ($ch === '[' && ($line[$at + 1] ?? '') === '^') {
            $end = $at + 2;
            $length = strlen($line);
            while ($end < $length && $line[$end] !== "\n" && $line[$end] !== ']') {
                $end++;
            }
            if (
                ($line[$end] ?? '') === ']'
                && ($line[$end + 1] ?? '') === ':'
                && ($line[$end + 2] ?? '') === ' '
            ) {
                return $end + 3;
            }
        }

        return null;
    }

    /**
     * Whether the line opens a list item, past any quote markers in front.
     */
    protected function startsAListItem(string $line, int $lineStart): bool
    {
        $at = $lineStart;
        while (true) {
            while (($line[$at] ?? '') === ' ') {
                $at++;
            }
            $ch = $line[$at] ?? '';
            if ($ch === '') {
                return false;
            }
            if ($ch === '>') {
                $end = $this->containerPrefixEnd($line, $at);
                if ($end === null) {
                    return false;
                }
                $at = $end;

                continue;
            }

            return $this->containerPrefixEnd($line, $at) !== null;
        }
    }

    /**
     * Every live `_` on the line a reader could pair into emphasis, by
     * CommonMark 6.2 read for the underscore: a run that flanks on both sides
     * can neither open nor close, which is what leaves `company_id` alone.
     *
     * ONE SCAN FOR THE WHOLE LINE, taken by the caller before it walks the
     * candidates. Asking per candidate costs a walk of the line each time, and a
     * memo keyed on the line only moves that cost into the comparison.
     *
     * @param string $line
     *
     * @return array<int, bool>
     */
    protected function pairableUnderscores(string $line): array
    {
        $open = [];
        $close = [];
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            if ($line[$i] !== '_' || !$this->isLiveAt($line, $i)) {
                continue;
            }
            $before = $this->characterBefore($line, $i);
            $after = $this->characterAfter($line, $i);
            $beforeSpace = $before === '' || $this->isFlankingWhitespace($before);
            $afterSpace = $after === '' || $this->isFlankingWhitespace($after);
            $beforePunctuation = $this->isFlankingPunctuation($before);
            $afterPunctuation = $this->isFlankingPunctuation($after);
            $left = !$afterSpace && (!$afterPunctuation || $beforeSpace || $beforePunctuation);
            $right = !$beforeSpace && (!$beforePunctuation || $afterSpace || $afterPunctuation);
            if ($left && (!$right || $beforePunctuation)) {
                $open[] = $i;
            }
            if ($right && (!$left || $afterPunctuation)) {
                $close[] = $i;
            }
        }

        // A pair needs an opener BEFORE a closer, so `x_ _y` has none: its closer
        // stands first. The two bounds are what decides it.
        $pairs = [];
        $firstOpen = $open !== [] ? $open[0] : PHP_INT_MAX;
        $lastClose = $close !== [] ? $close[count($close) - 1] : PHP_INT_MIN;
        foreach ($open as $i) {
            if ($i < $lastClose) {
                $pairs[$i] = true;
            }
        }
        foreach ($close as $i) {
            if ($i > $firstOpen) {
                $pairs[$i] = true;
            }
        }

        return $pairs;
    }

    /**
     * Whether the character at $offset is not covered by an odd run of
     * backslashes.
     *
     * @param string $line
     * @param int $offset
     *
     * @return bool
     */
    protected function isLiveAt(string $line, int $offset): bool
    {
        $backslashes = 0;
        for ($i = $offset - 1; $i >= 0 && $line[$i] === '\\'; $i--) {
            $backslashes++;
        }

        return $backslashes % 2 === 0;
    }

    /**
     * The whole UTF-8 character ending immediately before $offset.
     *
     * @param string $line
     * @param int $offset
     *
     * @return string
     */
    protected function characterBefore(string $line, int $offset): string
    {
        if ($offset <= 0) {
            return '';
        }
        $start = $offset - 1;
        while ($start > 0 && (ord($line[$start]) & 0xC0) === 0x80) {
            $start--;
        }

        return substr($line, $start, $offset - $start);
    }

    /**
     * The whole UTF-8 character beginning immediately after $offset.
     *
     * @param string $line
     * @param int $offset
     *
     * @return string
     */
    protected function characterAfter(string $line, int $offset): string
    {
        $length = strlen($line);
        $start = $offset + 1;
        if ($start >= $length) {
            return '';
        }
        $end = $start + 1;
        while ($end < $length && (ord($line[$end]) & 0xC0) === 0x80) {
            $end++;
        }

        return substr($line, $start, $end - $start);
    }

    /**
     * @param string $character
     *
     * @return bool
     */
    protected function isFlankingWhitespace(string $character): bool
    {
        return preg_match('/^' . static::FLANKING_WHITESPACE . '$/u', $character) === 1;
    }

    protected function renderNode(Node $node): string
    {
        if ($this->renderDepth >= self::MAX_RENDER_DEPTH) {
            throw new RenderDepthExceededException(self::MAX_RENDER_DEPTH, 'Markdown');
        }

        $this->renderDepth++;
        try {
            $eventName = 'render.' . $node->getType();
            if ($this->hasListenersFor($eventName)) {
                $event = new RenderEvent($node);
                $this->dispatchEvent($eventName, $event);
                $this->dispatchEvent('render.*', $event);

                if ($event->isDefaultPrevented()) {
                    return $event->getHtml() ?? '';
                }
            }

            // An unresolved reference renders as the source the author
            // wrote, never as a link (PART 12 section 3a).
            // PART 9 §23: a comment LINE publishes nothing, so the stored
            // source is emptied of them here rather than in the stored value -
            // `rawRef` stays the authored source verbatim for the canonical
            // writer (PART 12 §3a, carve-php#1417).
            $rawReference = UnresolvedReference::renderedSourceOf($node);

            return match (true) {
                $node instanceof Document => $this->renderChildren($node),
                // A BLOCK-position image needs the separator a paragraph would
                // have added. Decided by position, not by class: this match
                // covers inline nodes too, and a bare `instanceof Image` arm
                // here appended the separator to every inline image as well -
                // `see ![a](x.png) here` came out split across three lines.
                $node instanceof Image && $this->isBlockPositionImage($node)
                => $this->renderImage($node) . "\n\n",
                $node instanceof Paragraph => $this->renderParagraph($node),
                $node instanceof Heading => $this->renderHeading($node),
                $node instanceof CodeBlock => $this->renderCodeBlock($node),
                $node instanceof Comment => '', // Skip comments
                // PART 12 §18. The definition renders nothing where it sits
                // on every target; its entry renders in the references list the
                // Citations extension builds. Without this arm the default
                // branch renders the entry's children into the flow, which is
                // rendered output moving on a tree change that must not move it
                // (markup-carve/carve#1276).
                $node instanceof CitationDefinition => '',
                $node instanceof AbbreviationDefinition
                => $this->renderAbbreviationDefinition($node),
                $node instanceof RawBlock => $this->renderRawBlock($node),
                $node instanceof BlockQuote => $this->renderBlockQuote($node),
                $node instanceof ListBlock => $this->renderList($node),
                $node instanceof ListItem => $this->renderListItem($node),
                $node instanceof DefinitionList => $this->renderDefinitionList($node),
                $node instanceof DefinitionTerm => $this->renderDefinitionTerm($node),
                $node instanceof DefinitionDescription => $this->renderDefinitionDescription($node),
                // Always `---`, not the authored marker. The marker is not part
                // of the canonical AST -- carve-js, whose shape PART 12 pins, has
                // no field for it -- and this engine's OWN canonical writer
                // normalizes it too, so reproducing it here made the Markdown
                // target disagree with the Carve target of the same document
                // (carve#352, corpus 34 and 130). All three markers render as one
                // `<hr>`, so nothing is lost.
                $node instanceof ThematicBreak => "---\n\n",
                $node instanceof Div => $this->renderDiv($node),
                $node instanceof Table => $this->renderTable($node),
                $node instanceof LineBlock => $this->renderLineBlock($node),
                $node instanceof Footnote => $this->renderFootnote($node),
                $node instanceof Text => $this->escapeUnresolvedCrossrefs($this->stripControls($node->getContent())),
                // Keep the backslash so the literal stays literal when re-parsed as
                // Markdown: a bare `.` from `\.` would turn `1\. x` back into an
                // ordered list. EscapedText only ever holds escaped ASCII
                // punctuation, all of which CommonMark allows a `\` before.
                $node instanceof EscapedText => $this->renderEscapedText($node),
                $node instanceof FigureGroup => $this->renderFigureGroup($node),
                $node instanceof Figure => $this->renderFigure($node),
                $node instanceof Caption => $this->renderCaption($node),
                $node instanceof Abbreviation => $this->renderAbbreviation($node),
                $node instanceof Emphasis => $this->renderEmphasis($node),
                $node instanceof Strong => $this->renderStrong($node),
                $node instanceof Underline => $this->renderUnderline($node),
                $node instanceof Strike => $this->renderStrike($node),
                $node instanceof Code => $this->renderCode($node),
                $node instanceof Mention => $this->renderMention($node),
                $rawReference !== null => $this->escapeText($this->stripControls($rawReference)),
                $node instanceof Link => $this->renderLink($node),
                $node instanceof Image => $this->renderImage($node),
                // A BACKSLASH, not two trailing spaces (PART 11 section 9). Both
                // mean `<br />` to a CommonMark reader, but trailing whitespace is
                // removed by editors that strip on save, by
                // `git apply --whitespace=fix` and by CI whitespace checks -- and
                // losing ONE of the two spaces is enough for the break to vanish
                // rather than degrade, silently, in a file nobody edited.
                $node instanceof HardBreak => "\\\n",
                $node instanceof SoftBreak => match ($this->softBreakMode) {
                    SoftBreakMode::Newline => "\n",
                    SoftBreakMode::Space => ' ',
                    SoftBreakMode::Break => "  \n",
                },
                $node instanceof Superscript => $this->renderSuperscript($node),
                $node instanceof Subscript => $this->renderSubscript($node),
                $node instanceof Highlight => $this->renderHighlight($node),
                $node instanceof Insert => $this->renderInsert($node),
                $node instanceof Delete => $this->renderDelete($node),
                $node instanceof Substitution => $this->renderSubstitution($node),
                // Markdown has no critic syntax, so the text is what degrades
                // gracefully. Dropping it would make two targets of one engine
                // disagree about whether the document says it.
                $node instanceof CriticComment => $this->escapeText($this->stripControls($node->getContent())),
                $node instanceof Span => $this->renderSpan($node),
                $node instanceof Math => $this->renderMath($node),
                $node instanceof Symbol => ':' . $this->stripControls($node->getName()) . ':',
                $node instanceof InlineFootnote => '^[' . $this->renderChildren($node) . ']',
                $node instanceof FootnoteRef && $node->isUnresolved()
                => '\\[^' . $this->escapeHtml($this->stripControls($node->getLabel())) . '\\]',
                // Escaped like the definition, so the pair still matches. The
                // UNRESOLVED branch above already escapes, through escapeText()
                // (carve-php#1063).
                $node instanceof FootnoteRef
                => '[^' . $this->escapeHtml($this->stripControls($node->getLabel())) . ']',
                $node instanceof HeadingRef => $this->renderHeadingRef($node),
                $node instanceof CaptionNumber => $node->getNumber() === null ? '#' : (string)$node->getNumber(),
                $node instanceof RawInline => $this->renderRawInline($node),
                // §27: emitted by EVERY renderer, never dropped. It is prose,
                // not code, so no code fence -- the content becomes literal
                // text with Markdown metacharacters escaped so `*not bold*`
                // stays visible as authored.
                $node instanceof LiteralInline => $this->escapeText($this->stripControls($node->getContent())),
                $node instanceof RawText => $this->escapeText($this->stripControls($node->getContent())),
                $node instanceof SmartPunctuation => $this->renderSmartPunctuation($node),
                default => $this->renderChildren($node),
            };
        } finally {
            $this->renderDepth--;
        }
    }

    protected function renderHeadingRef(HeadingRef $node): string
    {
        $target = $node->getTargetId();
        // Exact match first, then a case-insensitive fallback so a lowercase
        // `</#getting-started>` resolves to a case-preserved id and the emitted
        // href uses the ACTUAL id (matches HtmlRenderer).
        $id = $this->headingIdTracker->findIdCaseInsensitive($target);
        $label = $id === null ? null : $this->headingIdTracker->getTextForId($id, $this->smartTypography);
        if ($id === null || $label === null) {
            // Unresolved target: keep the literal source (matches HtmlRenderer).
            // The authored marker stays readable rather than being escaped into
            // noise - a reader can still act on `</#nope>`. The TARGET inside it
            // is author content and can hold a `<`, and `</#a<script>` is a
            // complete opening tag once this Markdown is rendered, so the target
            // takes the HTML pass while the writer's own delimiters stay literal
            // (carve-php#1063).
            return '</#' . $this->escapeHtml($this->stripControls($target)) . '>';
        }

        // A heading target gets a real `[label](#id)` link — renderHeading emits a
        // matching `{#id}` anchor for it. A non-heading target (a numbered
        // figure/table caption) has no markdown anchor to point at, so its label
        // renders as plain text.
        // Same expansion budget the abbreviation arm spends, degrading to the
        // authored target (carve-php#1061). See AbbreviationBudgetTrait.
        //
        // THE LABEL IS THE HEADING'S INLINE NODES, rendered by THIS target
        // (PART 9R R4, markup-carve/carve#957): a heading holding a code span
        // comes back as a Markdown code span rather than as its bare content.
        // A caption id has no heading behind it and keeps the composed string.
        $nodes = $this->headingIdTracker->getLabelNodesForId($id);
        $rendered = $nodes === null
            ? $this->escapeText($label)
            : $this->renderDerivedLabel($nodes);
        if (!$this->chargeExpansion($rendered)) {
            $rendered = $this->escapeText($target);
        }

        if (isset($this->headingIds[$id])) {
            return '[' . $rendered . '](' . $this->markdownFragmentDestination($id) . ')';
        }

        return $rendered;
    }

    /**
     * Build a CommonMark link destination for a `#id` fragment. carve ids may
     * contain characters that break the bare `(...)` form (notably `(` / `)` and
     * whitespace, which carve accepts in an explicit `{#id}`); those are wrapped
     * in the angle-bracket destination form `<#id>` with `<`/`>`/`\` escaped.
     */
    protected function markdownFragmentDestination(string $id): string
    {
        if (preg_match('/[\s()<>]/', $id) !== 1) {
            return '#' . $id;
        }

        $escaped = str_replace(['\\', '<', '>'], ['\\\\', '\\<', '\\>'], $id);

        return '<#' . $escaped . '>';
    }

    protected function renderChildren(Node $node): string
    {
        $children = array_values($node->getChildren());
        $parts = [];
        foreach ($children as $child) {
            $parts[] = $this->renderNode($child);
        }

        return $this->reflankRuns($children, $parts);
    }

    /**
     * Whether a delimiter run can flank is a property of the SEAM, not of the
     * emphasis node, so it cannot be answered where the run is built: the
     * character that settles it belongs to the sibling on the other side. Here
     * is the one place both neighbours are known, so a run that can neither
     * open nor close where it stands is re-spelled as inline HTML - the same
     * fallback every inline this renderer cannot spell with delimiters already
     * takes (carve-php#1971).
     *
     * The neighbour is read off the parts as they stand, so a part already
     * re-spelled on this pass contributes its `>` or `<`. That only ever makes
     * a later run MORE able to flank, so the pass needs no second round.
     *
     * @param list<\MarkupCarve\Carve\Node\Node> $children
     * @param list<string> $parts
     */
    protected function reflankRuns(array $children, array $parts): string
    {
        // Three passes, because each one wants to see the parts the one before
        // it settled. A part re-spelled as inline HTML contributes a `<` or a
        // `>` where it used to contribute a delimiter, which can only make a
        // later run MORE able to flank and can only REMOVE a merge - so no pass
        // has to run twice, and a later pass never undoes an earlier one.
        foreach (array_keys($parts) as $index) {
            $piece = $this->delimiterPiece($children, $parts, $index);
            if ($piece !== null && $this->contentGrowsRun($piece, $children[$index])) {
                $parts[$index] = $this->spellAsHtml($piece);
            }
        }
        foreach (array_keys($parts) as $index) {
            $piece = $this->delimiterPiece($children, $parts, $index);
            if ($piece === null) {
                continue;
            }
            $character = $piece['delimiter'][0];
            $before = $piece['lead'] !== ''
                ? $this->lastCharacter($piece['lead'])
                : $this->neighbourBefore($parts, $index);
            $after = $piece['trail'] !== ''
                ? $this->firstCharacter($piece['trail'])
                : $this->neighbourAfter($parts, $index);
            // The INSIDE character is the one past everything the merged run
            // swallowed. A child's delimiter at the edge of the core is part of
            // the run the reader lexes, not content beside it, so reading it as
            // the inside character calls `a***x***b` unable to flank when it
            // flanks perfectly well.
            if (
                $this->flanks($this->afterRunInCore($piece['core'], $character), $before)
                && $this->flanks($this->beforeRunInCore($piece['core'], $character), $after)
            ) {
                continue;
            }

            $parts[$index] = $this->spellAsHtml($piece);
        }
        foreach (array_keys($parts) as $index) {
            $piece = $this->delimiterPiece($children, $parts, $index);
            if ($piece === null || !$this->seamMergesRun($children, $parts, $index, $piece)) {
                continue;
            }
            // ONE SEAM, ONE FALLBACK, AND IT IS THE RUN ON THE RIGHT that takes
            // it (markup-carve/carve#2045). A tilde seam is decided from both
            // sides and already reports against the right-hand strike, so only
            // the asterisk seam, which looks right only, moves its fallback
            // across.
            $next = $piece['delimiter'][0] === '~' ? -1 : $this->nextRendered($parts, $index);
            $right = $next < 0 ? null : $this->delimiterPiece($children, $parts, $next);
            if ($right !== null && $right['delimiter'][0] === $piece['delimiter'][0]) {
                array_splice($parts, $next, 1, [$this->spellAsHtml($right)]);
            } else {
                $parts[$index] = $this->spellAsHtml($piece);
            }
        }

        return implode('', $parts);
    }

    /**
     * The rendered part at $index taken back apart, or null when that node is
     * not one this renderer spells with a delimiter run.
     *
     * @param list<\MarkupCarve\Carve\Node\Node> $children
     * @param list<string> $parts
     * @param int $index
     *
     * @return array{delimiter: string, open: string, close: string, lead: string, core: string, trail: string}|null
     */
    protected function delimiterPiece(array $children, array $parts, int $index): ?array
    {
        $child = $children[$index];
        if (!$child instanceof Emphasis && !$child instanceof Strong && !$child instanceof Strike) {
            return null;
        }
        [$delimiter, $openTag, $closeTag] = $this->delimiterRun($child);
        $piece = $this->splitDelimitedRun($parts[$index], $delimiter);
        if ($piece === null) {
            return null;
        }
        [$lead, $core, $trail] = $piece;

        return [
            'delimiter' => $delimiter,
            'open' => $openTag,
            'close' => $closeTag,
            'lead' => $lead,
            'core' => $core,
            'trail' => $trail,
        ];
    }

    /**
     * @param array{delimiter: string, open: string, close: string, lead: string, core: string, trail: string} $piece
     */
    protected function spellAsHtml(array $piece): string
    {
        return $piece['lead'] . $piece['open'] . $piece['core'] . $piece['close'] . $piece['trail'];
    }

    protected function runAtStart(string $text, string $character): int
    {
        $length = strlen($text);
        $n = 0;
        while ($n < $length && $text[$n] === $character) {
            $n++;
        }

        return $n;
    }

    /**
     * A backslash escape makes the character it covers a literal, and a literal
     * breaks a delimiter run rather than lengthening it. The renderer escapes
     * every asterisk it means literally, so counting raw characters would read
     * the `*` of `x\*` as part of a run and get the summed length wrong by one.
     */
    protected function runAtEnd(string $text, string $character): int
    {
        $length = strlen($text);
        $n = 0;
        while ($n < $length && $text[$length - 1 - $n] === $character) {
            $n++;
        }
        if ($n === 0) {
            return 0;
        }
        $slashes = 0;
        while ($n + $slashes < $length && $text[$length - 1 - $n - $slashes] === '\\') {
            $slashes++;
        }

        return $slashes % 2 === 1 ? $n - 1 : $n;
    }

    /**
     * CommonMark 6.2's rule of 3, as a permission rather than a prohibition:
     * when a delimiter can both open and close, an opening run of $open and a
     * closing run of $close may not match if their lengths sum to a multiple of
     * three, unless both lengths are themselves multiples of three. A run this
     * renderer joins at a seam has content on both sides of it, so it can always
     * both open and close and the clause always applies.
     */
    protected function ruleOfThreeAllows(int $open, int $close): bool
    {
        if (($open + $close) % 3 !== 0) {
            return true;
        }

        return $open % 3 === 0 && $close % 3 === 0;
    }

    /**
     * Whether the run the renderer emitted is the run the READER lexes. Runs of
     * the same character that TOUCH are one run to the reader, of their summed
     * length, and the length is what decides what that run can do - so adjacency
     * is not the question, and no flanking test can answer it (carve-php#1974).
     *
     * This half is what the CONTENT contributes: a child's own delimiter, or a
     * literal the renderer did not escape. The reader re-pairs the merged run by
     * its own rule rather than by the nesting the document had - `***x***` comes
     * back emphasis outside strong whichever way it was written - so the parent
     * takes the inline-HTML spelling and the child keeps its delimiters. An
     * ESCAPED character at the edge reaches nothing, because a backslash breaks
     * a run rather than lengthening it.
     *
     * @param array{delimiter: string, open: string, close: string, lead: string, core: string, trail: string} $piece
     * @param \MarkupCarve\Carve\Node\Node $node
     */
    protected function contentGrowsRun(array $piece, Node $node): bool
    {
        $character = $piece['delimiter'][0];
        if (
            $this->runAtStart($piece['core'], $character) === 0
            && $this->runAtEnd($piece['core'], $character) === 0
        ) {
            return false;
        }

        return !$this->edgeChildNests($piece, $node);
    }

    /**
     * Whether every run the CONTENT adds at an edge belongs to a nested child of
     * a different strength, which the reader re-pairs as the nesting the
     * document has: `*italic **bold***` comes back as an emphasis holding a
     * strong.
     *
     * EQUAL strengths do not nest - the runs collapse into one element of the
     * wrong kind - and a run that is not a child's delimiter at all, a literal
     * the renderer did not escape, reaches the reader as part of the renderer's
     * own run. Both take the inline-HTML spelling instead.
     *
     * `***x***`, where the child spans the whole parent, is the case PART 11
     * section 10k's round-trip normalization list already allowed: the emphasis
     * comes back outside either way, and the two nestings are the same document.
     *
     * @param array{delimiter: string, open: string, close: string, lead: string, core: string, trail: string} $piece
     * @param \MarkupCarve\Carve\Node\Node $node
     */
    protected function edgeChildNests(array $piece, Node $node): bool
    {
        if ($piece['delimiter'][0] !== '*') {
            return false;
        }
        // The padding text nodes are not content: the renderer has already moved
        // them outside the delimiters, so a child at an edge of the core is
        // still the node whose delimiter stands there.
        $kids = [];
        foreach ($node->getChildren() as $kid) {
            if ($kid instanceof Text && trim($kid->getContent()) === '') {
                continue;
            }
            $kids[] = $kid;
        }
        if ($this->runAtStart($piece['core'], '*') > 0 && !$this->nestsInside($piece, $kids[0] ?? null)) {
            return false;
        }

        return $this->runAtEnd($piece['core'], '*') === 0
            || $this->nestsInside($piece, $kids !== [] ? $kids[count($kids) - 1] : null);
    }

    /**
     * @param array{delimiter: string, open: string, close: string, lead: string, core: string, trail: string} $piece
     * @param \MarkupCarve\Carve\Node\Node|null $kid
     */
    protected function nestsInside(array $piece, ?Node $kid): bool
    {
        if (!$kid instanceof Emphasis && !$kid instanceof Strong) {
            return false;
        }

        return strlen($this->delimiterRun($kid)[0]) !== strlen($piece['delimiter']);
    }

    /**
     * And this half is what the SIBLING across the seam contributes.
     *
     * For an asterisk the only thing that can reach the run from outside is
     * another run's delimiter, because a literal asterisk is escaped. Two
     * delimiters that touch are one run of their summed length, and three
     * questions decide whether the reader still resolves it the way the renderer
     * meant: the merged run has to be able to close for the left node and open
     * for the right one, which is CommonMark 6.2 read against the neighbours the
     * MERGED run has rather than the ones either half was built with; and the
     * rule of 3 has to allow both matches. `*x*` against `*y*` sums to two and
     * the rule of 3 refuses it; `**x**` against `*y*` sums to three and it is
     * allowed; `*x~*` against `**y**` also sums to three and still fails,
     * because a run whose inner character is `~` needs an outer character that
     * is not alphanumeric.
     *
     * A tilde run is not governed by the rule of 3 at all, and the readers do
     * not agree on it either: markdown-it pairs `~~` and splits a run of four,
     * while pulldown-cmark matches the run and does not. So no merged length
     * survives - any LIVE tilde reaching the run re-spells the strike as
     * inline HTML.
     *
     * @param list<\MarkupCarve\Carve\Node\Node> $children
     * @param list<string> $parts
     * @param int $index
     * @param array{delimiter: string, open: string, close: string, lead: string, core: string, trail: string} $piece
     */
    protected function seamMergesRun(array $children, array $parts, int $index, array $piece): bool
    {
        $character = $piece['delimiter'][0];
        if ($character === '~') {
            return $this->tildeSeamMerges($children, $parts, $index, $piece, -1)
                || $this->tildeSeamMerges($children, $parts, $index, $piece, 1);
        }
        if ($piece['trail'] !== '') {
            return false;
        }
        $next = $this->nextRendered($parts, $index);
        if ($next < 0 || $this->firstCharacter($parts[$next]) !== $character) {
            return false;
        }
        $other = $this->delimiterPiece($children, $parts, $next);
        if ($other === null || $other['delimiter'][0] !== $character || $other['lead'] !== '') {
            return true;
        }
        $inner = $this->beforeRunInCore($piece['core'], $character);
        $outer = $this->afterRunInCore($other['core'], $character);
        if ($inner === '' || $outer === '' || !$this->mergedRunFlanks($inner, $outer)) {
            return true;
        }
        $closing = $this->runAtEnd($piece['core'], $character) + strlen($piece['delimiter']);
        $opening = strlen($other['delimiter']) + $this->runAtStart($other['core'], $character);
        $merged = $closing + $opening;

        return !($this->ruleOfThreeAllows($closing, $merged) && $this->ruleOfThreeAllows($merged, $opening));
    }

    /**
     * @param list<\MarkupCarve\Carve\Node\Node> $children
     * @param list<string> $parts
     * @param int $index
     * @param array{delimiter: string, open: string, close: string, lead: string, core: string, trail: string} $piece
     * @param int $direction
     */
    protected function tildeSeamMerges(array $children, array $parts, int $index, array $piece, int $direction): bool
    {
        if (($direction < 0 ? $piece['lead'] : $piece['trail']) !== '') {
            return false;
        }
        $other = $direction < 0 ? $this->previousRendered($parts, $index) : $this->nextRendered($parts, $index);
        if ($other < 0) {
            return false;
        }
        // A backslash in front of the neighbour's last tilde makes it a literal,
        // which breaks the run rather than lengthening it; a tilde the neighbour
        // PRESENTS first is never escaped, because the backslash stands there.
        if ($direction < 0) {
            return $this->runAtEnd($parts[$other], '~') > 0;
        }
        if ($this->firstCharacter($parts[$other]) !== '~') {
            return false;
        }
        // One seam needs one fallback, and the strike on the right takes it: it
        // reaches the same seam from its own side, so leaving it there keeps the
        // left node in delimiters.
        $piece2 = $this->delimiterPiece($children, $parts, $other);

        return !($piece2 !== null && $piece2['delimiter'] === '~~' && $piece2['lead'] === '');
    }

    /**
     * The character on the far side of everything the merged run swallowed. The
     * delimiter's own neighbour is no use here: for `***x***` against `*y*` it
     * is another asterisk, which is INSIDE the merged run, and reading it as the
     * outer neighbour would call a run unable to flank that flanks perfectly
     * well.
     */
    protected function beforeRunInCore(string $core, string $character): string
    {
        return $this->lastCharacter(substr($core, 0, strlen($core) - $this->runAtEnd($core, $character)));
    }

    protected function afterRunInCore(string $core, string $character): string
    {
        return $this->firstCharacter(substr($core, $this->runAtStart($core, $character)));
    }

    /**
     * CommonMark 6.2 read against the neighbours the MERGED run has. Each half
     * was built against a neighbour that is no longer there: the character
     * outside the merged run is the content of the sibling across the seam. The
     * run has to be able to close for the node on its left and open for the node
     * on its right, which is the same test in both directions.
     */
    protected function mergedRunFlanks(string $inner, string $outer): bool
    {
        return $this->flanks($inner, $outer) && $this->flanks($outer, $inner);
    }

    /**
     * @param list<string> $parts
     * @param int $index
     */
    protected function nextRendered(array $parts, int $index): int
    {
        for ($i = $index + 1, $count = count($parts); $i < $count; $i++) {
            if ($parts[$i] !== '') {
                return $i;
            }
        }

        return -1;
    }

    /**
     * @param list<string> $parts
     * @param int $index
     */
    protected function previousRendered(array $parts, int $index): int
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if ($parts[$i] !== '') {
                return $i;
            }
        }

        return -1;
    }

    /**
     * The delimiter and the inline-HTML fallback for the three inlines this
     * renderer spells with a run. The three render arms read the same table, so
     * the seam pass can always recognise its own output.
     *
     * @return array{string, string, string}
     */
    protected function delimiterRun(Emphasis|Strong|Strike $node): array
    {
        return match (true) {
            $node instanceof Emphasis => ['*', '<em>', '</em>'],
            $node instanceof Strong => ['**', '<strong>', '</strong>'],
            default => ['~~', '<del>', '</del>'],
        };
    }

    /**
     * Take a rendered part back apart into the pieces padOutside() built it
     * from, or null when it is not a delimiter run at all - an empty render, the
     * inline-HTML form padOutside() falls back to for whitespace-only content,
     * or a run whose content ends in the backslash of a hard break, which
     * padOutside() moved out with the newline it belongs to.
     *
     * @return array{string, string, string}|null
     */
    protected function splitDelimitedRun(string $part, string $delimiter): ?array
    {
        $lead = $this->flankingRunAtStart($part);
        $rest = substr($part, strlen($lead));
        $trail = $this->flankingRunAtEnd($rest);
        $body = substr($rest, 0, strlen($rest) - strlen($trail));
        $width = strlen($delimiter);
        if (strlen($body) <= $width * 2) {
            return null;
        }
        if (!str_starts_with($body, $delimiter) || !str_ends_with($body, $delimiter)) {
            return null;
        }

        return [$lead, substr($body, $width, -$width), $trail];
    }

    /**
     * One side of CommonMark 6.2, and it really is ONE side: left-flanking and
     * right-flanking are the same test read in opposite directions. A run is
     * left-flanking when the character INSIDE it is not whitespace and, if that
     * character is punctuation, the character OUTSIDE is whitespace or
     * punctuation; right-flanking swaps which end is inside. padOutside() has
     * already moved every space out of the run, so the inside character is never
     * whitespace and only the punctuation clause is left to decide.
     *
     * No neighbour at all counts as whitespace: the enclosing text either starts
     * or ends the line, or it is a delimiter, a bracket or a tag belonging to
     * whatever encloses the run - punctuation in every case this renderer can
     * produce.
     */
    protected function flanks(string $inside, string $outside): bool
    {
        if (!$this->isFlankingPunctuation($this->flankCharacter($inside))) {
            return true;
        }

        $outer = $this->flankCharacter($outside);
        if ($outer === '') {
            return true;
        }

        return preg_match('/^' . static::FLANKING_WHITESPACE . '$/u', $outer) === 1
            || $this->isFlankingPunctuation($outer);
    }

    /**
     * CommonMark 0.31 punctuation: ASCII punctuation plus the Unicode P* and S*
     * categories. The S* half is the reason to spell it out - 0.30 left the
     * symbol categories out, so a reader on either version agrees about `!` and
     * disagrees about `(c)`, and taking the WIDER class is the answer that is
     * right under both: it can only move a construct to inline HTML, which every
     * reader reads the same way.
     */
    protected function isFlankingPunctuation(string $character): bool
    {
        return $character !== '' && preg_match('/^[\p{P}\p{S}]$/u', $character) === 1;
    }

    /**
     * A sentinel stands for `_`, `#` or `[`, all three of them punctuation, and
     * it is a private-use code point that no punctuation property matches. The
     * flanking test therefore has to ask about the character the reader will
     * see, not the carrier standing in for it until the escapes resolve.
     */
    protected function flankCharacter(string $character): string
    {
        foreach ([$this->narrowedSentinels, $this->authoredSentinels, $this->authoredKeptSentinels] as $map) {
            $found = array_search($character, $map, true);
            if ($found !== false) {
                return (string)$found;
            }
        }

        return $character;
    }

    /**
     * @param list<string> $parts
     * @param int $index
     */
    protected function neighbourBefore(array $parts, int $index): string
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if ($parts[$i] !== '') {
                return $this->lastCharacter($parts[$i]);
            }
        }

        return '';
    }

    /**
     * @param list<string> $parts
     * @param int $index
     */
    protected function neighbourAfter(array $parts, int $index): string
    {
        for ($i = $index + 1, $count = count($parts); $i < $count; $i++) {
            if ($parts[$i] !== '') {
                return $this->firstCharacter($parts[$i]);
            }
        }

        return '';
    }

    protected function firstCharacter(string $text): string
    {
        return preg_match('/^./us', $text, $matches) === 1 ? $matches[0] : '';
    }

    protected function lastCharacter(string $text): string
    {
        return preg_match('/.$/usD', $text, $matches) === 1 ? $matches[0] : '';
    }

    protected function renderParagraph(Paragraph $node): string
    {
        return $this->protectParagraphListMarkers($this->renderChildren($node)) . "\n\n";
    }

    /**
     * Keep paragraph continuation lines from becoming lists in Markdown readers.
     */
    protected function protectParagraphListMarkers(string $text): string
    {
        $codeFence = 0;
        $lines = explode("\n", $text);
        foreach ($lines as &$line) {
            if ($codeFence === 0) {
                $line = (string)preg_replace('/^([ \t]{0,3})([-+])(?=[ \t])/', '$1\\\\$2', $line);
                $line = (string)preg_replace('/^([ \t]{0,3}\d{1,9})([.)])(?=[ \t])/', '$1\\\\$2', $line);
            }

            $length = strlen($line);
            for ($i = 0; $i < $length;) {
                if ($line[$i] !== '`') {
                    $i++;

                    continue;
                }
                $backslashes = 0;
                for ($j = $i - 1; $j >= 0 && $line[$j] === '\\'; $j--) {
                    $backslashes++;
                }
                $run = 1;
                while ($i + $run < $length && $line[$i + $run] === '`') {
                    $run++;
                }
                if ($backslashes % 2 === 0) {
                    if ($codeFence === 0) {
                        $codeFence = $run;
                    } elseif ($codeFence === $run) {
                        $codeFence = 0;
                    }
                }
                $i += $run;
            }
        }
        unset($line);

        return implode("\n", $lines);
    }

    /**
     * Walk the tree once, recording each heading's resolved id (into
     * $this->headingIds) and every `</#id>` target id (into $referencedIds).
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<string, true> $referencedIds
     * @param int $depth
     *
     * @throws \MarkupCarve\Carve\Exception\RenderDepthExceededException
     */
    protected function collectHeadingAndRefIds(Node $node, array &$referencedIds, int $depth = 0): void
    {
        if ($depth >= self::MAX_RENDER_DEPTH) {
            throw new RenderDepthExceededException(self::MAX_RENDER_DEPTH, 'Markdown');
        }

        if ($node instanceof Heading) {
            $this->headingIds[$this->headingIdTracker->getIdForHeading($node)] = true;
        } elseif ($node instanceof HeadingRef) {
            // Record the ACTUAL (case-preserved) heading id a `</#id>` resolves
            // to, so a heading that is a case-insensitive crossref target still
            // emits its `{#id}` anchor.
            $resolvedId = $this->headingIdTracker->findIdCaseInsensitive($node->getTargetId());
            if ($resolvedId !== null) {
                $referencedIds[$resolvedId] = true;
            }
        } elseif ($node instanceof Link) {
            $destination = $node->getDestination();
            if ($destination !== null && str_starts_with($destination, '#')) {
                $resolvedId = $this->headingIdTracker->findIdCaseInsensitive(substr($destination, 1));
                if ($resolvedId !== null) {
                    $referencedIds[$resolvedId] = true;
                }
            }
        }

        foreach ($node->getChildren() as $child) {
            $this->collectHeadingAndRefIds($child, $referencedIds, $depth + 1);
        }
    }

    protected function renderHeading(Heading $node): string
    {
        $prefix = str_repeat('#', $node->getLevel()) . ' ';
        // A Markdown heading is a single line, so a multi-line carve heading
        // (lazy continuation, `# Foo\nbar`) is flattened to one line. This also
        // keeps a trailing `{#id}` attribute on the actual heading line.
        $text = trim(
            (string)preg_replace('/[ \t\r\n]*\n[ \t\r\n]*/', ' ', $this->renderChildren($node)),
            StringUtil::TRIMMABLE_WHITESPACE,
        );
        $id = $this->headingIdTracker->getIdForHeading($node);
        // A referenced heading carries an explicit `{#id}` (pandoc/kramdown) so
        // the `[label](#id)` link pointing at it resolves to a real anchor.
        $suffix = isset($this->referencedHeadingIds[$id]) ? ' {#' . $id . '}' : '';

        return $prefix . ($suffix === '' ? $this->keepTrailingHashRun($text) : $text) . $suffix . "\n\n";
    }

    /**
     * Keep the escape on a heading line's trailing run of hashes (PART 11 §8a
     * M1f). Every CommonMark reader takes such a run as the ATX closing
     * sequence and drops it, so `# a ##` reaches the reader as `a`. The escape
     * goes on the run's FIRST hash: the rest no longer follow a space.
     *
     * Only a run a space or tab opens is a closing sequence, and only at the
     * line's end - so a heading carrying a `{#id}` suffix has none, and neither
     * has `# a##`.
     */
    protected function keepTrailingHashRun(string $text): string
    {
        $undecided = $this->authoredSentinels['#'];
        $width = strlen($undecided);

        $end = strlen($text);
        $at = $end;
        while ($at >= $width && substr($text, $at - $width, $width) === $undecided) {
            $at -= $width;
        }

        if ($at === $end) {
            return $text;
        }

        // The `# ` the writer puts in front is the space a whole-text run opens
        // against. Bytes throughout: no UTF-8 continuation byte is a space or a
        // tab, so the byte before the run answers it.
        $opener = $at === 0 ? ' ' : $text[$at - 1];
        if ($opener !== ' ' && $opener !== "\t") {
            return $text;
        }

        return substr($text, 0, $at) . $this->authoredKeptSentinels['#'] . substr($text, $at + $width);
    }

    protected function renderCodeBlock(CodeBlock $node): string
    {
        // Keep only the first whitespace-delimited token (the language word);
        // drop it if it still contains a backtick (would break the fence).
        $language = $this->stripControls($node->getLanguage() ?? '');
        $language = preg_split('/\s/', $language, 2)[0] ?? '';
        if (str_contains($language, '`')) {
            $language = '';
        }
        $content = $this->stripControls($node->getContent());

        $backticks = StringUtil::findSafeCodeFence($content, 3);

        // Re-emit the fence header ("title") and label ([label]) so this
        // structured metadata survives carve -> markdown conversion. Order and
        // spacing follow the carve#201 fence grammar (lang "Header" [Label]) so a
        // carve reader round-trips it; generic markdown reads the language token
        // and ignores the rest. The header is only emitted when a language is
        // present, since a leading quote with no language is not a valid fence
        // header (it would fall back to an inline code span).
        // Backticks are stripped from the title/label (as they are from the
        // language above) so the emitted opener can never contain a backtick run
        // that clashes with the fence delimiter, which would break re-parsing.
        $info = $language;
        if ($language !== '') {
            $title = $node->getAttribute('title');
            if (is_string($title) && $title !== '') {
                $info .= ' "' . str_replace(['"', '`'], '', $this->stripControls($title)) . '"';
            }
        }
        $label = $node->getLabel();
        if ($label !== null && $label !== '') {
            $info .= ' [' . str_replace(['[', ']', '`'], '', $this->stripControls($label)) . ']';
        }

        return $backticks . $info . "\n" . $content . "\n" . $backticks . "\n\n";
    }

    protected function renderBlockQuote(BlockQuote $node): string
    {
        $body = $this->containerContent(function () use ($node): string {
            $this->inBlockQuote = true;
            $content = $this->renderChildren($node);
            $this->inBlockQuote = false;

            return $content;
        });

        // Prefix each line with >, and a blank line with a bare marker.
        $lines = explode("\n", $body);
        $quoted = array_map(fn ($line) => $line === '' ? '>' : '> ' . $line, $lines);

        return implode("\n", $quoted) . "\n\n";
    }

    protected function renderList(ListBlock $node): string
    {
        $this->listDepth++;
        $output = '';
        $counter = $node->getStart();

        foreach ($node->getChildren() as $child) {
            if ($child instanceof ListItem) {
                if ($node->getListType() === ListBlock::TYPE_ORDERED) {
                    // Normalize to standard Markdown: numeric with . or )
                    // Roman/alpha styles and (n) format are Carve-specific
                    $marker = $node->getMarker();
                    if ($marker === '()' || $marker === null) {
                        $marker = '.';
                    }
                    $prefix = $counter . $marker . ' ';
                    $counter++;
                } elseif ($node->getListType() === ListBlock::TYPE_TASK) {
                    $marker = $node->getMarker() ?? '-';
                    $checkbox = $child->getChecked() ? '[x] ' : '[ ] ';
                    $prefix = $marker . ' ' . $checkbox;
                } else {
                    $marker = $node->getMarker() ?? '-';
                    $prefix = $marker . ' ';
                }

                $content = $this->containerContent(fn (): string => $this->renderChildren($child));
                // Handle multi-line list items
                $lines = explode("\n", $content);
                $firstLine = array_shift($lines);
                $output .= ($firstLine === '' ? rtrim($prefix, ' ') : $prefix . $firstLine) . "\n";

                if ($lines) {
                    // Every continuation line moves to this item's content
                    // column, and a nested list is one of them: the child list
                    // emits its markers flush and THIS pad is what nests it.
                    // Padding by the list's own depth as well indented each
                    // level twice, which put a third level ten columns in -
                    // four past its parent's content column, where a reader
                    // opens an indented verbatim block instead of a list.
                    //
                    // A line with no content takes no padding: PART 11 section
                    // 7 emits such a line empty, and trailing whitespace is
                    // what editors and `git apply --whitespace=fix` rewrite
                    // behind the writer.
                    $continuation = str_repeat(' ', strlen($prefix));
                    foreach ($lines as $line) {
                        $output .= ($line === '' ? '' : $continuation . $line) . "\n";
                    }
                }
            }
        }

        $this->listDepth--;

        return $output . ($this->listDepth === 0 ? "\n" : '');
    }

    protected function renderListItem(ListItem $node): string
    {
        return $this->renderChildren($node);
    }

    protected function renderDefinitionList(DefinitionList $node): string
    {
        // Markdown doesn't have native definition lists
        // Use HTML or approximate with bold term
        $output = '';
        foreach ($node->getChildren() as $child) {
            $output .= $this->renderNode($child);
        }

        return $output . "\n";
    }

    protected function renderDefinitionTerm(DefinitionTerm $node): string
    {
        return $this->padOutsideOnItsOwnLine($this->renderChildren($node), '**', '<strong>', '</strong>') . "\n";
    }

    protected function renderDefinitionDescription(DefinitionDescription $node): string
    {
        $content = $this->containerContent(fn (): string => $this->renderChildren($node));

        return ':' . ($content === '' ? '' : ' ' . $content) . "\n";
    }

    protected function renderDiv(Div $node): string
    {
        // Divs/admonitions don't exist in Markdown; render the content. An
        // admonition's quoted opener header would otherwise be lost; preserve
        // it as a leading bold line.
        $body = $this->renderChildren($node);
        $prefix = '';
        $title = $node->getHeader();
        if (is_string($title)) {
            $prefix .= $this->padOutsideOnItsOwnLine(
                $this->renderTitleInlineNodes($node->getHeaderNodes()),
                '**',
                '<strong>',
                '</strong>',
            ) . "\n\n";
        }
        // PROPOSAL (graceful degradation): a grouping `[label]` (grammar PART 9
        // §12) is normally consumed by a group extension (e.g. tabs). When no
        // extension replaced this div, surface the label as a leading bold line
        // so it is not silently dropped. Title (if any) renders first, then the
        // label. Diverges from the current spec corpus pending adoption.
        $label = $node->getLabel();
        if ($label !== null && $label !== '') {
            $prefix .= $this->padOutsideOnItsOwnLine(
                $this->escapeText($this->stripControls($label)),
                '**',
                '<strong>',
                '</strong>',
            ) . "\n\n";
        }

        if ($this->attributeFallback === AttributeFallback::Html) {
            $attrs = $this->htmlAttributes($node);
            if ($attrs !== '') {
                // Blank lines inside the wrapper on purpose: a `<div>` line
                // followed by one ends the raw HTML block (CommonMark HTML block
                // type 6), so the body is still read as Markdown rather than as
                // one opaque chunk. The title/label lines stay INSIDE - they are
                // content this container introduces.
                return '<div' . $attrs . ">\n\n"
                    . rtrim($prefix . $body, "\n")
                    . "\n\n</div>\n\n";
            }
        }

        return $prefix . $body;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     */
    protected function renderTitleInlineNodes(array $nodes): string
    {
        $flat = $this->unwrapTitleStrong($nodes);
        $parts = [];
        foreach ($flat as $node) {
            $parts[] = $this->renderNode($node);
        }

        return $this->reflankRuns($flat, $parts);
    }

    /**
     * The title's own siblings, with the strong wrappers taken off.
     *
     * Unwrapping the NODES rather than concatenating their rendered strings is
     * what lets the title share the seam pass in reflankRuns(): the pass needs a
     * node and its rendered part side by side, and a strong rendered through
     * renderTitleInlineNodes() is not a delimiter run at all.
     *
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     *
     * @return list<\MarkupCarve\Carve\Node\Node>
     */
    protected function unwrapTitleStrong(array $nodes): array
    {
        $flat = [];
        foreach ($nodes as $node) {
            if ($node instanceof Strong) {
                foreach ($this->unwrapTitleStrong(array_values($node->getChildren())) as $inner) {
                    $flat[] = $inner;
                }

                continue;
            }

            $flat[] = $node;
        }

        return $flat;
    }

    protected function renderTable(Table $node): string
    {
        $layout = TableLayout::expand(
            $node,
            fn (TableCell $cell): array => [
                'content' => trim($this->renderChildren($cell), StringUtil::TRIMMABLE_WHITESPACE),
                'alignment' => $cell->getAlignment(),
            ],
        );

        $headerCells = null;
        $bodyRows = [];
        $alignments = [];

        foreach ($layout['rows'] as $row) {
            $cells = [];
            foreach ($row['cells'] as $index => $cell) {
                // A ROW KEEPS ITS OWN CELL COUNT. TableLayout::expand pads every
                // row out to the widest one so a renderer with no colspan can
                // draw a rectangle; this target re-parses, so the padding would
                // become cells. `authoredWidth` is the count before that padding
                // - a column a span CLAIMED is authored and stays, a column the
                // row never reached is dropped.
                if ($index >= $row['authoredWidth']) {
                    break;
                }
                if (is_array($cell) && isset($cell['content']) && is_string($cell['content'])) {
                    $cells[] = $cell['content'];
                } else {
                    $cells[] = '';
                }
            }

            if ($row['isHeader']) {
                // Multiple header rows collapse to Markdown's one header row.
                // The later header's effective column alignment wins in Carve,
                // so update the delimiter source on every header.
                foreach ($row['cells'] as $index => $cell) {
                    if (
                        $index < $row['authoredWidth']
                        && is_array($cell)
                        && is_string($cell['alignment'] ?? null)
                        && $cell['alignment'] !== TableCell::ALIGN_DEFAULT
                    ) {
                        $alignments[$index] = $cell['alignment'];
                    }
                }
                if ($headerCells === null) {
                    // No trailing-empty pop here: an EMPTY authored cell is a
                    // cell, and `authoredWidth` already removed the padding.
                    $headerCells = $cells;
                } else {
                    // Markdown cannot retain another header row, but dropping
                    // it would violate the presentation target's content floor.
                    $bodyRows[] = '| ' . implode(' | ', $cells) . ' |';
                }
            } else {
                $bodyRows[] = '| ' . implode(' | ', $cells) . ' |';
            }
        }

        $output = '';
        if ($headerCells !== null) {
            $output .= '| ' . implode(' | ', $headerCells) . ' |' . "\n";

            // The delimiter promotes the header row, so PART 11 §10b requires
            // exactly one delimiter cell per header cell. Using the table's
            // maximum width makes common Markdown readers reject a ragged table
            // whose body is wider than its header (markup-carve/carve#1042).
            $separators = [];
            $headerCellCount = count($headerCells);
            for ($index = 0; $index < $headerCellCount; $index++) {
                $align = $alignments[$index] ?? TableCell::ALIGN_DEFAULT;
                $separators[] = match ($align) {
                    TableCell::ALIGN_LEFT => ':---',
                    TableCell::ALIGN_CENTER => ':---:',
                    TableCell::ALIGN_RIGHT => '---:',
                    default => '---',
                };
            }
            $output .= '| ' . implode(' | ', $separators) . ' |' . "\n";
        }

        $output .= implode("\n", $bodyRows) . "\n";

        $caption = $node->getCaption();
        if ($caption !== null) {
            $text = trim($this->renderChildren($caption), StringUtil::TRIMMABLE_WHITESPACE);
            if ($text !== '') {
                $output .= "\n" . $text . "\n";
            }
        }

        return $output . "\n";
    }

    protected function renderLineBlock(LineBlock $node): string
    {
        // Line blocks don't exist in Markdown, so the line structure is carried
        // by hard breaks -- which the PARSER already put in the AST, one per
        // newline inside the block. Rewriting every newline here added a second
        // hard break on top of each of those, and turned the blank line between
        // two stanzas into a pair of them:
        //
        //   before   Stanza one,[4 spaces]\nstill one.[2 spaces]\n[2 spaces]\n...
        //   after    Stanza one,[2 spaces]\nstill one.\n\n...
        //
        // carve-js and carve-rs both emit the second form (carve#352).
        return trim($this->renderChildren($node), StringUtil::TRIMMABLE_WHITESPACE) . "\n\n";
    }

    protected function renderFootnote(Footnote $node): string
    {
        $content = $this->containerContent(fn (): string => $this->renderChildren($node));

        // A label is author content, and it is reproduced verbatim in two
        // places; both escape, so a reference still matches its definition
        // (carve-php#1063).
        return '[^' . $this->escapeHtml($this->stripControls($node->getLabel())) . ']: ' . $content . "\n";
    }

    protected function renderEmphasis(Emphasis $node): string
    {
        [$delimiter, $openTag, $closeTag] = $this->delimiterRun($node);

        return $this->padOutside($this->renderChildren($node), $delimiter, $openTag, $closeTag);
    }

    protected function renderStrong(Strong $node): string
    {
        [$delimiter, $openTag, $closeTag] = $this->delimiterRun($node);

        return $this->padOutside($this->renderChildren($node), $delimiter, $openTag, $closeTag);
    }

    /**
     * The whitespace a CommonMark READER counts when it decides flanking:
     * general category Zs plus tab, line feed, form feed and carriage return
     * (CommonMark 2.1), plus this renderer's own nbsp sentinel.
     *
     * NOT `StringUtil::TRIMMABLE_WHITESPACE`, which is the four ASCII bytes
     * `trim()` can take as a charlist. That class misses every Zs above the
     * ASCII range - a NO-BREAK SPACE most of all - so a run padded with one
     * was written adjacent to its delimiters and read back as literal text.
     *
     * U+E000 is in the class because it IS a no-break space at this point: it
     * is what an authored `\ ` carries until resolveEscapes swaps it for
     * U+00A0 at the very end of render(), long after this test has run.
     *
     * The class is the Unicode White_Space property rather than CommonMark
     * 2.1's narrower one, because the READER decides whether a run flanks:
     * pulldown-cmark counts U+000B, U+2028 and U+2029 as whitespace, so a run
     * left beside one never opens (markup-carve/carve#2023).
     *
     * @var string
     */
    protected const FLANKING_WHITESPACE =
        '(?:[ \t\n\x{000B}\f\r]|\x{0085}|\x{00A0}|\x{1680}|[\x{2000}-\x{200A}]|\x{2028}|\x{2029}|\x{202F}|\x{205F}|\x{3000}|\x{E000})';

    /**
     * A delimiter run only opens emphasis while it is left-flanking, which a
     * run followed by whitespace never is (CommonMark 6.2), so `** x**` reads
     * back as literal text. The padding is content, so it moves outside the
     * delimiters rather than being trimmed away. Content that is only padding
     * has no delimiter form at all and falls back to inline HTML, the way this
     * renderer already spells underline, sub, super and highlight.
     */
    protected function padOutside(string $inner, string $delimiter, string $openTag, string $closeTag): string
    {
        $lead = $this->flankingRunAtStart($inner);
        $rest = substr($inner, strlen($lead));
        $trail = $this->flankingRunAtEnd($rest);
        $core = substr($rest, 0, strlen($rest) - strlen($trail));

        // A hard break is a BACKSLASH then a newline. Moving the newline alone
        // would leave the backslash against the closing delimiter, escaping it
        // (`**a\**` reads back as a literal asterisk and a stray `<em>`), so
        // the backslash travels with the newline it belongs to.
        if ($trail !== '' && $trail[0] === "\n" && $this->endsInAnEscapingBackslash($core)) {
            $trail = '\\' . $trail;
            $core = substr($core, 0, -1);
        }

        if ($core === '') {
            return $inner === '' ? '' : $openTag . $inner . $closeTag;
        }

        return $lead . $delimiter . $core . $delimiter . $trail;
    }

    /**
     * The same repair for the wrapper lines that spell a delimiter run
     * themselves. Those sit on a line of their own, where outer ASCII padding
     * is not content - and a line that ends in a space either means nothing or
     * means a hard break nobody asked for.
     */
    protected function padOutsideOnItsOwnLine(string $inner, string $delimiter, string $openTag, string $closeTag): string
    {
        return trim($this->padOutside($inner, $delimiter, $openTag, $closeTag), StringUtil::TRIMMABLE_WHITESPACE);
    }

    protected function flankingRunAtStart(string $text): string
    {
        $matched = preg_match('/^' . static::FLANKING_WHITESPACE . '+/u', $text, $matches);
        if ($matched === 1) {
            return $matches[0];
        }
        if ($matched === false) {
            // Malformed UTF-8 defeats a `/u` pattern outright. The ASCII half
            // of the class still holds, and losing the padding repair is a far
            // smaller failure than losing the run.
            return substr($text, 0, strlen($text) - strlen(ltrim($text, StringUtil::TRIMMABLE_WHITESPACE)));
        }

        return '';
    }

    protected function flankingRunAtEnd(string $text): string
    {
        $matched = preg_match('/' . static::FLANKING_WHITESPACE . '+$/u', $text, $matches);
        if ($matched === 1) {
            return $matches[0];
        }
        if ($matched === false) {
            return substr($text, strlen(rtrim($text, StringUtil::TRIMMABLE_WHITESPACE)));
        }

        return '';
    }

    /**
     * True when the final backslash is an ESCAPE rather than an escaped
     * backslash - i.e. the trailing run of backslashes has odd length.
     */
    protected function endsInAnEscapingBackslash(string $text): bool
    {
        $run = strlen($text) - strlen(rtrim($text, '\\'));

        return $run % 2 === 1;
    }

    protected function renderCode(Code $node): string
    {
        $content = $this->stripControls($node->getContent());

        $backticks = StringUtil::findSafeCodeFence($content, 1);

        // Add spaces if content starts/ends with backtick
        if (str_starts_with($content, '`') || str_ends_with($content, '`')) {
            return $backticks . ' ' . $content . ' ' . $backticks;
        }

        return $backticks . $content . $backticks;
    }

    protected function renderMention(Mention $node): string
    {
        // A mention/tag with no configured URL renders as plain text.
        if (($node->getDestination() ?? '') === '') {
            return $this->renderChildren($node);
        }

        return $this->renderLink($node);
    }

    protected function renderLink(Link $node): string
    {
        $text = $this->renderChildren($node);
        $url = $this->encodeMarkdownDestination((string)$node->getDestination());
        $title = $node->getTitle();

        if ($title !== null) {
            return '[' . $text . '](' . $url . ' "' . $this->escapeTitle($this->stripControls($title)) . '")';
        }

        return '[' . $text . '](' . $url . ')';
    }

    /**
     * A lone image is a block-level image node (#633), so it takes the block
     * separator. An image inside a paragraph or another inline is inline.
     */
    protected function isBlockPositionImage(Image $node): bool
    {
        $parent = $node->getParent();

        return $parent !== null && !$parent instanceof Paragraph && !$parent instanceof InlineNode;
    }

    protected function renderImage(Image $node): string
    {
        if ($this->attributeFallback === AttributeFallback::Html) {
            // Exclude exactly the names the tag spells itself: `src` and `alt`
            // always, `title` only when the image carries one. Emitting a name
            // twice is invalid HTML, and an HTML parser keeps the first
            // occurrence, so the shadowed copy would be inert anyway - dropping
            // it changes nothing a consumer could read. When the shadowed copy
            // was the ONLY attribute, nothing is left to carry and the ordinary
            // Markdown image (which already spells alt and title) is emitted.
            $exclude = ['src', 'alt'];
            if ($node->getTitle() !== null) {
                $exclude[] = 'title';
            }

            $attrs = $this->htmlAttributes($node, $exclude);
            if ($attrs !== '') {
                return $this->renderImageTag($node, $attrs);
            }
        }

        $alt = $this->escapeImageAlt($this->stripControls($node->getAlt()));
        $src = $this->encodeMarkdownDestination((string)$node->getSource());
        $title = $node->getTitle();

        if ($title !== null) {
            return '![' . $alt . '](' . $src . ' "' . $this->escapeTitle($this->stripControls($title)) . '")';
        }

        return '![' . $alt . '](' . $src . ')';
    }

    /**
     * An attributed image as a raw `<img>` tag, mirroring the HTML target's
     * `src` / `alt` / `title` / attribute order.
     *
     * `src` runs through the same denylist a Markdown destination gets: a raw
     * tag is the more direct sink of the two, so it cannot be laxer
     * (carve-php#462, PART 9 section 25).
     *
     * @param \MarkupCarve\Carve\Node\Inline\Image $node
     * @param string $attrs Pre-serialized attributes, with the names this method
     *   spells itself already excluded.
     */
    protected function renderImageTag(Image $node, string $attrs): string
    {
        $serializer = $this->attributeSerializer();
        $src = $this->stripControls($this->sanitizeUrl((string)$node->getSource()));
        $html = '<img src="' . $serializer->escapeAttribute($src) . '"'
            . ' alt="' . $serializer->escapeAttribute($this->stripControls($node->getAlt())) . '"';

        $title = $node->getTitle();
        if ($title !== null) {
            $html .= ' title="' . $serializer->escapeAttribute($this->stripControls($title)) . '"';
        }

        return $html . $attrs . '>';
    }

    protected function renderSuperscript(Superscript $node): string
    {
        // Markdown doesn't have native superscript, use HTML
        return '<sup>' . $this->renderChildren($node) . '</sup>';
    }

    protected function escapeTitle(string $title): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $title);
    }

    protected function escapeImageAlt(string $alt): string
    {
        return str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $alt);
    }

    protected function renderSubscript(Subscript $node): string
    {
        // Markdown doesn't have native subscript, use HTML
        return '<sub>' . $this->renderChildren($node) . '</sub>';
    }

    protected function renderHighlight(Highlight $node): string
    {
        // Markdown doesn't have native highlight, use HTML
        return '<mark>' . $this->renderChildren($node) . '</mark>';
    }

    protected function renderInsert(Insert $node): string
    {
        // Use HTML ins tag
        return '<ins>' . $this->renderChildren($node) . '</ins>';
    }

    protected function renderDelete(Delete $node): string
    {
        // Markdown has no native critic deletion distinct from strikethrough.
        return '<del>' . $this->renderChildren($node) . '</del>';
    }

    protected function renderSubstitution(Substitution $node): string
    {
        return '<del>' . $this->renderChildren($node->getOld()) . '</del>'
            . '<ins>' . $this->renderChildren($node->getNew()) . '</ins>';
    }

    protected function renderUnderline(Underline $node): string
    {
        // Markdown has no native underline; emit raw HTML.
        return '<u>' . $this->renderChildren($node) . '</u>';
    }

    protected function renderStrike(Strike $node): string
    {
        [$delimiter, $openTag, $closeTag] = $this->delimiterRun($node);

        return $this->padOutside($this->renderChildren($node), $delimiter, $openTag, $closeTag);
    }

    /**
     * Set while rendering a span that carries an authored `abbr`.
     *
     * PART 9 §10 and markup-carve/carve#1127: the authored value OUTRANKS
     * automatic expansion, and a resolved abbreviation inside such a span
     * contributes only its visible text - a renderer must not emit the nested
     * expansion. The HTML renderer already carried this flag; this target
     * emitted the DEFINITION's text instead (markup-carve/carve#1176).
     */
    protected bool $suppressAutomaticAbbreviation = false;

    protected function renderSpan(Span $node): string
    {
        // Spans with attributes don't exist in Markdown, so the content is
        // rendered bare - EXCEPT for an authored `abbr`, which outranks the
        // document definition (markup-carve/carve#1127). This target can carry
        // a title, because it already emits an `<abbr>` for an ordinary
        // expansion, so it carries the AUTHORED one (markup-carve/carve#1176).
        $authored = $node->getAttributes()['abbr'] ?? null;
        if (!is_string($authored)) {
            return $this->renderChildren($node);
        }

        $previous = $this->suppressAutomaticAbbreviation;
        $this->suppressAutomaticAbbreviation = true;

        try {
            $inner = $this->renderChildren($node);
        } finally {
            $this->suppressAutomaticAbbreviation = $previous;
        }

        if ($authored === '' || !$this->chargeAbbreviationExpansion($authored)) {
            return $inner;
        }

        $title = htmlspecialchars($this->stripControls($authored), ENT_QUOTES, 'UTF-8');

        return '<abbr title="' . $title . '">' . $inner . '</abbr>';
    }

    protected function renderMath(Math $node): string
    {
        $content = $this->escapeHtml($this->stripControls($node->getContent()));

        if ($node->isDisplay()) {
            return '$$' . $content . '$$';
        }

        return '$' . $content . '$';
    }

    protected function renderRawBlock(RawBlock $node): string
    {
        if ($node->getFormat() === 'html') {
            // Escape, not emit: raw HTML in Markdown output would be live again
            // when the Markdown is rendered to HTML downstream.
            return $this->escapeHtml($this->stripControls($node->getContent())) . "\n\n";
        }

        $this->recordRawFormatDropped($node, $node->getFormat(), 'block');

        return '';
    }

    protected function renderRawInline(RawInline $node): string
    {
        if ($node->getFormat() === 'html') {
            return $this->escapeHtml($this->stripControls($node->getContent()));
        }

        $this->recordRawFormatDropped($node, $node->getFormat(), 'inline');

        return '';
    }

    /**
     * A figure renders its target then its caption as a separate block
     * (Markdown has no <figure>). A BLANK line before the caption is required,
     * not just a newline: against a block-quote target a single newline would
     * make the caption a lazy continuation of the quote and swallow it.
     */
    protected function renderFigure(Figure $node): string
    {
        $target = null;
        foreach ($node->getChildren() as $child) {
            if (!$child instanceof Caption) {
                $target = $child;
            }
        }
        // The caption sits on its own line directly under the figure (`\n`),
        // matching carve-js / carve-rs; a blockquote target keeps the
        // blank-line separation.
        $sep = $target instanceof BlockQuote ? "\n\n" : "\n";

        $output = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Caption) {
                $output = rtrim($output, StringUtil::TRIMMABLE_WHITESPACE) . $sep . $this->renderCaption($child);
            } else {
                $output .= $this->renderNode($child);
            }
        }

        return $output;
    }

    protected function renderCaption(Caption $node): string
    {
        return rtrim($this->renderChildren($node), StringUtil::TRIMMABLE_WHITESPACE) . "\n\n";
    }

    /**
     * A composite figure (grammar PART 11 §10g T1). Markdown has no figure
     * grouping, so this is the spelling the admonition title rule already
     * uses for authored text with no native slot: panels in order, each host
     * degraded as usual, each PANEL caption as an emphasized `*...*` paragraph
     * after its host; preserved stray content in place; the GROUP caption
     * last, as a bold `**...**` paragraph, its number resolved.
     */
    protected function renderFigureGroup(FigureGroup $node): string
    {
        $output = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Figure) {
                $panelCaption = null;
                $host = '';
                foreach ($child->getChildren() as $part) {
                    if ($part instanceof Caption) {
                        $panelCaption = $part;
                    } else {
                        $host .= $this->renderNode($part);
                    }
                }
                $output .= rtrim($host, StringUtil::TRIMMABLE_WHITESPACE) . "\n\n";
                if ($panelCaption !== null) {
                    $output .= $this->padOutsideOnItsOwnLine(
                        $this->renderChildren($panelCaption),
                        '*',
                        '<em>',
                        '</em>',
                    ) . "\n\n";
                }
            } else {
                // A table panel keeps its caption inside its own degradation,
                // and stray non-panel content is preserved in place.
                $output .= $this->renderNode($child);
            }
        }

        $caption = $node->getCaption();
        if ($caption !== null) {
            $output .= $this->padOutsideOnItsOwnLine(
                $this->renderChildren($caption),
                '**',
                '<strong>',
                '</strong>',
            ) . "\n\n";
        }

        return $output;
    }

    /**
     * Markdown has no abbreviation syntax; emit inline <abbr> so the title is
     * preserved (mirrors how subscript/superscript fall back to inline HTML).
     */
    protected function renderAbbreviation(Abbreviation $node): string
    {
        // The whole element is raw inline HTML, so both the title (attribute)
        // and the text (element content) need HTML escaping, NOT Markdown text
        // escaping: a `"` in the title or a `<` in the text would otherwise
        // break the tag / be misparsed as markup downstream.
        $text = htmlspecialchars($this->renderChildren($node), ENT_QUOTES, 'UTF-8');

        // Inside a span carrying its own `abbr`, only the visible text
        // (markup-carve/carve#1127).
        if ($this->suppressAutomaticAbbreviation) {
            return $text;
        }

        // DoS guard: once the cumulative expansion bytes would exceed the
        // budget, degrade to plain key text (no <abbr> wrapper, no title).
        if (!$this->chargeAbbreviationExpansion($node->getTitle())) {
            return $text;
        }

        $title = htmlspecialchars($this->stripControls($node->getTitle()), ENT_QUOTES, 'UTF-8');

        return '<abbr title="' . $title . '">' . $text . '</abbr>';
    }

    /**
     * The crossref production, spelled exactly as the parser spells it.
     *
     * Two producers for one production is how this class of defect starts, so
     * the id ends at PART 7's four characters here as well.
     *
     * @var string
     */
    protected const UNRESOLVED_CROSSREF_PATTERN = '/<\/#([^> \t\r\n]+)>/u';

    /**
     * Escape a text value, leaving any UNRESOLVED crossref marker readable.
     */
    protected function escapeUnresolvedCrossrefs(string $text): string
    {
        if (preg_match_all(self::UNRESOLVED_CROSSREF_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return $this->escapeText($text);
        }

        $out = '';
        $last = 0;
        foreach ($matches[0] as $index => $match) {
            [$marker, $offset] = $match;
            $out .= $this->escapeText(substr($text, $last, $offset - $last))
                . '</#' . $this->escapeHtml($matches[1][$index][0]) . '>';
            $last = $offset + strlen($marker);
        }

        return $out . $this->escapeText(substr($text, $last));
    }

    protected function escapeText(string $text): string
    {
        // Neutralize embedded HTML first, so Markdown later re-rendered to HTML
        // cannot execute it: carve's "HTML is text" guarantee holds for the
        // Markdown target too (a literal `<img onerror=…>` in text becomes
        // inert `&lt;img …&gt;`).
        //
        // ONLY `<` AND `>` DO THAT WORK. A bare `&` cannot open a tag: an entity
        // in Markdown TEXT decodes to a CHARACTER, and a character in text
        // content is escaped again by whatever writes the HTML. Measured against
        // pandoc 3.5, commonmark.js and marked with raw HTML ALLOWED - the
        // entity and bare forms came out byte-identical and inert, while a bare
        // `<` was live in all three. Escaping every ampersand cost every
        // document its spelling for nothing: on one real corpus 324 of 423
        // escaped characters were ampersands (carve#1071).
        //
        // NO EXCEPTION FOR A CHARACTER-REFERENCE OPENER, deliberately. Text
        // authored as `&#65;` is emitted as itself. Whether an `&` opens a
        // reference depends on the EMITTED LINE, and Carve parses `#65` as a
        // tag, so this renderer sees two separate text nodes - answering it here
        // would be one node too early, the mistake §8a M1b documents for `_`,
        // `#` and `[`.
        // Escape special Markdown characters in text. None overlap with the angle
        // brackets handled after it.
        //
        // `_`, `#` and `[` are emitted as SENTINELS rather than as backslashes:
        // PART 11 §8a decides those three on the EMITTED LINE, which only
        // resolveNarrowedEscapes() can see. `*` keeps M1 unconditionally (M1a),
        // and every other metacharacter keeps M1 as written (M1c).
        //
        // THE HASH TAKES M1f's CARRIER, not M1b's. Its test is positional
        // rather than adjacency, and a container settles it at the prefix site.
        //
        // `~` IS ONE OF THEM. GFM's strikethrough extension pairs a run of ONE
        // OR TWO tildes, so a literal tilde in text is a Markdown
        // metacharacter, and §8a narrows only `_`, `#`, `[` and `<` - M1d
        // leaves every other one on M1. Unescaped, two literal tildes anywhere
        // in one paragraph pair across whatever markup stands between them and
        // the tags interleave (carve-php#1976); a single one pairs the same way
        // for a reader that takes the one-tilde form, which pulldown-cmark does.
        $escaped = preg_replace_callback(
            '/([\\\\`*_~\[\]#])/',
            fn (array $m): string => $m[1] === '#'
                ? $this->positionalHash('#')
                : ($this->narrowedSentinels[$m[1]] ?? '\\' . $m[1]),
            $text,
        ) ?? $text;

        return preg_replace('/<(?=[A-Za-z\/!?])/', '\\\\<', $escaped) ?? $escaped;
    }

    /**
     * Blank a URL whose (normalized) scheme is on the dangerous denylist, so a
     * `javascript:` link/image does not survive into Markdown output (and from
     * there into a downstream Markdown -> HTML render). Mirrors the HTML
     * renderer's always-on URL baseline.
     */

    /**
     * Escape `<`, `>`, `&` so embedded HTML cannot become live markup when the
     * Markdown is re-rendered to HTML.
     */
    protected function escapeHtml(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    /**
     * Blank a URL whose scheme is on the denylist, using the HTML renderer's one
     * implementation rather than a copy.
     *
     * The copy that used to live here listed four schemes and probed with an
     * ASCII-only strip, so the twenty OS protocol-handler schemes (`ms-msdt`,
     * `search-ms`, `shell`, `vscode`, `jar`, ...) reached the output, and
     * `\u{202F}javascript:` slipped past -- both blanked by the HTML renderer.
     * A Markdown destination is resolved by whatever renders that Markdown, so
     * this is the same sink one step removed (PART 9 section 25,
     * markup-carve/carve#385).
     */
    protected function sanitizeUrl(string $url): string
    {
        return HtmlRenderer::blankDangerousScheme($url);
    }

    /**
     * A node's attributes serialized for a raw HTML tag, by the HTML renderer
     * itself: name validation (`on*` handlers, the `srcdoc` / `formaction` sinks,
     * and the identifier check that closed a name-level bypass), value hardening
     * (the URL denylist, CSS `expression(...)`) and attribute-context escaping all
     * come from that one implementation. A copy here would be free to drift into
     * being the laxer of the two, which is exactly how the URL denylist diverged.
     *
     * Returns `''` when nothing survives, so an attribute-less container gets no
     * pointless wrapper.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<string> $exclude Attribute names the caller spells itself.
     */
    protected function htmlAttributes(Node $node, array $exclude = []): string
    {
        $attrs = $this->attributeSerializer()->renderAttributesExcluding($node, $exclude);

        // A newline in a value would put a blank line inside the opening tag,
        // ending the raw HTML block early and leaving the closing tag dangling as
        // literal text. Tabs and newlines survive stripControls() by design, so
        // fold them here; every other control character is already gone.
        return (string)preg_replace('/[\t\n]+/', ' ', $this->stripControls($attrs));
    }

    /**
     * The HTML renderer used for attribute serialization only, built on first use.
     */
    protected function attributeSerializer(): HtmlRenderer
    {
        return $this->attributeSerializer ??= new HtmlRenderer();
    }

    /**
     * Encode a destination for the Markdown output, refusing a denied scheme.
     *
     * The order is the point. This writer NORMALIZES the destination before it
     * emits it - it drops control characters, and its consumer decodes
     * character references - so the probe has to run on the normalized form.
     * The control-character half is already right here (`blankDangerousScheme()`
     * strips `\p{Cc}` inside the probe, which is why carve-js and carve-rs let
     * `java<DEL>script:` through and this engine did not); the character
     * references were not (carve-php#1062).
     */
    protected function encodeMarkdownDestination(string $url): string
    {
        $url = $this->sanitizeUrl($this->stripControls($url));
        $url = strtr($url, [
            ' ' => '%20',
            '(' => '%28',
            ')' => '%29',
            '<' => '%3C',
            '>' => '%3E',
        ]);

        return $this->neutralizeCharacterReferences($url);
    }

    /**
     * Escape every ampersand that OPENS an HTML character reference.
     */
    protected function neutralizeCharacterReferences(string $url): string
    {
        return (string)preg_replace(
            '/&(?=#[0-9]{1,8};|#[xX][0-9a-fA-F]{1,8};|[a-zA-Z][a-zA-Z0-9]{0,31};)/',
            '&amp;',
            $url,
        );
    }

    /**
     * Drop the control characters this target does NOT emit.
     *
     * PART 9 §29 C0 CONTROLS ON THE RENDER TARGETS: after
     * markup-carve/carve#963 the whitespace of the language is exactly U+0020,
     * U+0009, U+000A and U+000D, and every OTHER C0 control - U+0000..U+0008,
     * U+000B, U+000C, U+000E..U+001F - is ordinary CONTENT. PART 9 §29 T2 has the Markdown target EMIT the class. A target that
     * deletes it is lossy in the way markup-carve/carve#817 rejected for the
     * wire, and the reason first offered for the strip - that a Markdown reader
     * reclassifies these characters as whitespace - was measured against the
     * CommonMark reference implementation and markdown-it in three modes and did
     * not hold: all four keep them, and `-<VT>item` opens no list in any of them.
     *
     * WHAT STILL GOES. U+000D is WHITESPACE, not content, so it is stripped like
     * the other whitespace this writer normalizes. DEL (U+007F) and the C1
     * controls U+0080..U+009F stay stripped too: §29 T5 puts them outside that
     * section, and this engine is deliberately the strict one there - CSI
     * (U+009B) and OSC (U+009D) are single-character forms of the sequences §25
     * exists to stop.
     *
     * THE NARROWED-ESCAPE SENTINELS USED TO GO HERE TOO, and no longer do.
     * They are chosen per document now, so no author content can carry one, and
     * deleting three private-use code points to protect a fixed marker was the
     * same lossy strip this section rejects for the C0 class
     * (markup-carve/carve-php#1087).
     *
     * The terminal target keeps its own broad strip; see
     * AnsiRenderer::stripControls(). Narrowing THAT one would be a security
     * regression, which is why the three targets spell this separately rather
     * than sharing one function.
     */
    protected function stripControls(string $text): string
    {
        $text = (string)preg_replace('/[\x{000D}\x{007F}-\x{009F}]/u', '', $text);

        return str_contains($text, "\xE2")
            ? (string)preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text)
            : $text;
    }
}
