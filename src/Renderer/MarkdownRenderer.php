<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Exception\RenderDepthExceededException;
use MarkupCarve\Carve\Node\Block\AbbreviationDefinition;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Caption;
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
 *
 * This renderer converts Djot AST to Markdown syntax. The output is designed
 * for consumption by Markdown parsers, not Djot parsers. For round-trip
 * stability, the Markdown output should be re-parsed by a Markdown parser.
 *
 * Syntax mapping (Djot → Markdown):
 * - Emphasis: `_text_` → `*text*`
 * - Strong: `*text*` → `**text**`
 *
 * Note: Some Djot features don't have direct Markdown equivalents
 * and will be rendered as HTML or approximated.
 *
 * Attributes are the one case where the approximation is a policy choice rather
 * than a mapping. Markdown has no block container and no attribute syntax on an
 * image, so a `::: class` div and an `![alt](src){.class}` drop their
 * `{#id .class data-*}` by default - right for the human-facing export this
 * target is normally used for, data loss for a consumer treating Markdown as an
 * interchange format. `setAttributeFallback(AttributeFallback::Html)` keeps them
 * as raw HTML instead, the way an inline mark already degrades to `<mark>`
 * (carve-php#458).
 *
 * Options: setSoftBreakMode(), setSmartTypography(), setAttributeFallback().
 */
class MarkdownRenderer implements RendererInterface
{
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
     * The characters PART 11 §8a M1b decides on the LINE, in the order the
     * sentinel run is assigned to them.
     *
     * THE ASTERISK IS NOT HERE, and that is M1a rather than an omission. This
     * writer spells emphasis with `*`, so a literal asterisk is not a character
     * that MIGHT meet markup on the line - it is the character the line's markup
     * is made of. `*\*\**` unescaped to `****`, and a CommonMark reader
     * publishes emphasis-containing-two-asterisks as a thematic break.
     *
     * @var list<string>
     */
    private const NARROWED_CHARACTERS = ['_', '#', '['];

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
     * They used to be the fixed U+E004..U+E006, and the way author content was
     * kept away from them was to DELETE the whole range in stripControls() on
     * the way in. That is the collision, not a defense against it: PART 7 makes
     * every character that is not one of the four whitespace characters
     * CONTENT, and PART 9 §29 already answered what this target does with
     * content it did not expect - it EMITS it, because "a target that silently
     * deletes content is lossy rather than safe". The strip was the same
     * decision §29 rejected for the C0 controls, applied to three private-use
     * code points instead, so `a<U+E004>b` came out `ab`
     * (markup-carve/carve-php#1087).
     *
     * Picking instead of stripping removes both halves at once: the sentinels
     * cannot be authored, so nothing has to be deleted to protect them.
     *
     * @var array<string, string>
     */
    protected array $narrowedSentinels = [
        '_' => "\u{E004}",
        '#' => "\u{E005}",
        '[' => "\u{E006}",
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
    protected string $narrowedSentinelClass = '[\x{E004}-\x{E006}]';

    protected int $listDepth = 0;

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
     *
     * PART 10 §10a: a definition NOTHING references is still emitted by this
     * target. HTML drops it because it has nowhere to put one; Markdown, plain
     * text and the terminal do not get to drop content the author wrote, and
     * dropping it made the output depend on whether a reference exists
     * elsewhere in the document (carve#589).
     *
     * They live on the document rather than in `children` here, so unlike
     * carve-js and carve-rs this renderer places them itself - before the body
     * or after it, following where the author put them.
     *
     * FROM THE NODES, not from the document's side list. A profile removes the
     * AbbreviationDefinition NODE; the list is a second source of truth, so
     * reading it emitted the line for a definition the host had denied - on this
     * target and not on HTML, where the line never appears anyway
     * (carve-php#858). Same shape as the numbering that lived in the render
     * context (#843) and the profile that reached only the render path (#853).
     *
     * The expansion still comes from the map, and must: denying the definition
     * denies the definition, and the inline `abbreviation` it feeds is a separate
     * profile entry that keeps rendering.
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
        return '\\' . $this->stripControls($node->getContent());
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
        $run = DocumentSentinels::pick(
            DocumentSentinels::collectStrings($document),
            count(self::NARROWED_CHARACTERS),
            self::NARROWED_SENTINEL_FIRST,
        );

        $this->narrowedSentinels = array_combine(self::NARROWED_CHARACTERS, $run);
        $this->narrowedSentinelClass = '[' . $run[0] . '-' . $run[count($run) - 1] . ']';
    }

    /**
     * Resolve the narrowed escapes: PART 11 §8a, M1b.
     *
     * `_`, `#` and `[` are escaped IF AND ONLY IF the character is ADJACENT on
     * the emitted line to an UNESCAPED DELIMITER OF THE SAME CHARACTER.
     *
     * Adjacent, and unescaping would MERGE THE TWO INTO ONE RUN, which every
     * Markdown reader this target answers to resolves by run length - so the
     * escape is holding a run boundary apart under all of them at once, and it
     * is kept. Not adjacent, and the escape protects nothing under any of them:
     * `company_id`, `C#` and `issue #123` are written as the author typed them,
     * and a backslash inside an identifier no longer breaks exact-match search
     * in the published document.
     *
     * IF AND ONLY IF, NOT A FLOOR. An escape this drops is dropped and an escape
     * it keeps is kept. §8a is explicit that it is not a minimum to build a
     * wider narrowing on, because a permissive reading yields three outputs from
     * three engines - which is the failure the question came out of.
     *
     * Runs on the assembled output rather than in escapeText() because the test
     * is a property of the LINE, not of one node: the parser splits `company_id`
     * into the text nodes `company` and `_id`, so at escape time the underscore
     * looks like it starts a word.
     *
     * It decides on the sentinel rather than on `\_` because the assembled
     * document also contains regions this renderer must reproduce byte-exact -
     * code spans, code blocks, link destinations, titles, raw HTML - and a
     * backslash there is content, not an escape. Matching `\_` rewrote those
     * too (carve-js issue 400).
     */
    protected function resolveNarrowedEscapes(string $markdown): string
    {
        $pattern = '/' . $this->narrowedSentinelClass . '/u';

        if (preg_match_all($pattern, $markdown, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return $markdown;
        }

        $character = array_flip($this->narrowedSentinels);

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
        $out = '';
        $read = 0;
        foreach ($matches[0] as $index => [$sentinel, $offset]) {
            $offset = (int)$offset;
            $char = $character[$sentinel];

            $out .= substr($markdown, $read, $offset - $read);
            $out .= $this->adjacentToLiveDelimiter($line, $offset - 2 * $index, $char)
                ? '\\' . $char
                : $char;
            $read = $offset + strlen($sentinel);
        }

        return $out . substr($markdown, $read);
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

    protected function renderNode(Node $node): string
    {
        if ($this->renderDepth >= self::MAX_RENDER_DEPTH) {
            throw new RenderDepthExceededException(self::MAX_RENDER_DEPTH, 'Markdown');
        }

        $this->renderDepth++;
        try {
            if ($this->hasAnyListeners()) {
                $eventName = 'render.' . $node->getType();
                $event = new RenderEvent($node);
                $this->dispatchEvent($eventName, $event);
                $this->dispatchEvent('render.*', $event);

                if ($event->isDefaultPrevented()) {
                    return $event->getHtml() ?? '';
                }
            }

            // An unresolved reference renders as the source the author
            // wrote, never as a link (PART 12 section 3a).
            $rawReference = UnresolvedReference::sourceOf($node);

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
                // Unresolved: the reference never formed, so the literal source
                // is what is emitted -- and BOTH brackets are escaped, not just
                // the closer.
                //
                // This used to run the whole run through escapeText(), which
                // applies the section 8a M1b narrowing: `[` is escaped only when
                // it is adjacent on the emitted line to another `[`, so the
                // opener came back bare and only the `]` kept its backslash.
                // M1b is a rule about a character that reached this writer
                // inside a TEXT node, one "the Carve grammar did not read as an
                // opener"; the grammar DID read this one, which is why there is
                // a FootnoteRef node here at all. What the writer emits is a
                // whole construct opener, and section 8a is explicit that
                // dropping an escape "is an argument owed once per reader"
                // while the adjacency case "owes none".
                //
                // The argument is owed and it fails. Under python-markdown's
                // footnotes extension `[^a\]:` is read as a footnote DEFINITION
                // whose label is `a\`, so a document that degraded the construct
                // to literal text published a footnote section it never had -
                // and the half-escaped run is what section 2 calls "a shape that
                // happens to work rather than one that says what it means"
                // (markup-carve/carve#1040).
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
        $output = '';
        foreach ($node->getChildren() as $child) {
            $output .= $this->renderNode($child);
        }

        return $output;
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

        return $prefix . $text . $suffix . "\n\n";
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
        $this->inBlockQuote = true;
        $content = $this->renderChildren($node);
        $this->inBlockQuote = false;

        $body = trim($content, StringUtil::TRIMMABLE_WHITESPACE);

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
                    // Roman/alpha styles and (n) format are Djot-specific
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

                $content = trim($this->renderChildren($child), StringUtil::TRIMMABLE_WHITESPACE);
                // Handle multi-line list items
                $lines = explode("\n", $content);
                $firstLine = array_shift($lines);
                $output .= $prefix . $firstLine . "\n";

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
        return '**' . $this->renderChildren($node) . "**\n";
    }

    protected function renderDefinitionDescription(DefinitionDescription $node): string
    {
        return ': ' . trim($this->renderChildren($node), StringUtil::TRIMMABLE_WHITESPACE) . "\n";
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
            $prefix .= '**' . $this->renderTitleInlineNodes($node->getHeaderNodes()) . "**\n\n";
        }
        // PROPOSAL (graceful degradation): a grouping `[label]` (grammar PART 9
        // §12) is normally consumed by a group extension (e.g. tabs). When no
        // extension replaced this div, surface the label as a leading bold line
        // so it is not silently dropped. Title (if any) renders first, then the
        // label. Diverges from the current spec corpus pending adoption.
        $label = $node->getLabel();
        if ($label !== null && $label !== '') {
            $prefix .= '**' . $this->escapeText($this->stripControls($label)) . "**\n\n";
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
        $output = '';
        foreach ($nodes as $node) {
            $output .= $this->renderTitleInlineNode($node);
        }

        return $output;
    }

    protected function renderTitleInlineNode(Node $node): string
    {
        if ($node instanceof Strong) {
            return $this->renderTitleInlineNodes($node->getChildren());
        }

        return $this->renderNode($node);
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
                    if (!$row['isHeader'] && !isset($alignments[$index])) {
                        $alignments[$index] = is_string($cell['alignment'] ?? null)
                            ? $cell['alignment']
                            : TableCell::ALIGN_DEFAULT;
                    }
                } else {
                    $cells[] = '';
                }
            }

            if ($row['isHeader'] && $headerCells === null) {
                // No trailing-empty pop here: an EMPTY authored cell is a cell,
                // and `authoredWidth` above already removed the padding that
                // pop was aimed at.
                $headerCells = $cells;
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

        // PART 11 §10e T2: a caption is authored text, and Markdown has no
        // table-caption syntax, so it survives as BODY TEXT AFTER the table,
        // SEPARATED BY ONE BLANK LINE. An image caption and a listing caption
        // already take that position on this target, so the table was the odd
        // one out rather than a consequence of Markdown lacking the syntax.
        //
        // The blank line is load-bearing, not cosmetic. §10e states the general
        // form: attachment by adjacency is only available on a target where
        // adjacency does not change what the adjacent block IS. Written directly
        // under the last row, a GFM reader takes the caption as ANOTHER ROW and
        // returns it as `<td>Fruit prices</td>` - the words survive as a
        // fabricated data cell no reader can tell from an authored one, which is
        // worse than losing them. So this half accepts an attachment weaker than
        // §10d's: the floor is being met, not a relationship preserved.
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
        $content = trim($this->renderChildren($node), StringUtil::TRIMMABLE_WHITESPACE);

        // A label is author content, and it is reproduced verbatim in two
        // places; both escape, so a reference still matches its definition
        // (carve-php#1063).
        return '[^' . $this->escapeHtml($this->stripControls($node->getLabel())) . ']: ' . $content . "\n";
    }

    protected function renderEmphasis(Emphasis $node): string
    {
        return '*' . $this->renderChildren($node) . '*';
    }

    protected function renderStrong(Strong $node): string
    {
        return '**' . $this->renderChildren($node) . '**';
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
        return '<del>' . $this->escapeHtml($this->stripControls($node->getOldText())) . '</del>'
            . '<ins>' . $this->escapeHtml($this->stripControls($node->getNewText())) . '</ins>';
    }

    protected function renderUnderline(Underline $node): string
    {
        // Markdown has no native underline; emit raw HTML.
        return '<u>' . $this->renderChildren($node) . '</u>';
    }

    protected function renderStrike(Strike $node): string
    {
        return '~~' . $this->renderChildren($node) . '~~';
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
        // Escaped, exactly as the HTML target escapes the same content: a
        // consumer decodes the entity back to the character before its math
        // renderer sees it, so `a < b` still reaches KaTeX as `a < b` while
        // `<script>` cannot become a tag (carve-php#1063).
        //
        // That covers the ampersand too, which a LaTeX matrix uses as its
        // alignment separator: `a & b` is emitted `a &amp; b` and a Markdown
        // consumer hands `a & b` to the math renderer. Re-parsing the Markdown
        // with CARVE instead does not decode it - but that is not this target's
        // contract, the `carve` target is (PART 11 section 1), and it is exactly
        // what escapeText() already does to every other text node here
        // (raised by codex review).
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

        return '';
    }

    protected function renderRawInline(RawInline $node): string
    {
        if ($node->getFormat() === 'html') {
            return $this->escapeHtml($this->stripControls($node->getContent()));
        }

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
        return trim($this->renderChildren($node), StringUtil::TRIMMABLE_WHITESPACE) . "\n\n";
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
                    $output .= '*' . trim($this->renderChildren($panelCaption), StringUtil::TRIMMABLE_WHITESPACE) . "*\n\n";
                }
            } else {
                // A table panel keeps its caption inside its own degradation,
                // and stray non-panel content is preserved in place.
                $output .= $this->renderNode($child);
            }
        }

        $caption = $node->getCaption();
        if ($caption !== null) {
            $output .= '**' . trim($this->renderChildren($caption), StringUtil::TRIMMABLE_WHITESPACE) . "**\n\n";
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
     *
     * `</#nope>` is source the resolver declined. `renderHeadingRef()` already
     * emits it with its own delimiters literal - "a reader can still act on
     * `</#nope>`" (carve-php#1063) - but a crossref inside a LINK never reaches
     * that method: `CrossReferenceResolver::headingRefToLabel()` flattens it to
     * a Text node first, because a crossref inside a link would render as a
     * nested anchor. So the marker arrived here as ordinary text and M1e escaped
     * its `<`, and one engine spelled one construct two ways depending on where
     * it stood. This was the only Markdown divergence carve-php had left across
     * the 1006-document corpus (markup-carve/carve#1147).
     *
     * THE ESCAPE PROTECTS NOTHING, measured rather than assumed. A CommonMark
     * tag name must begin with an ASCII letter, so `</#` opens nothing; through
     * commonmark 0.31.2 and marked 18.0.9 the escaped and bare spellings of
     * `a [t</#nope>](/u) b` parse to the same HTML. M1e's `/` case is written on
     * the next character alone, which is right for `</b>` and over-broad here.
     *
     * THE TARGET STILL TAKES THE HTML PASS, which is not carve-out noise: the id
     * is author content and may hold a `<`, and `</#a<script>` emitted verbatim
     * is a live tag in both readers. Only the writer's own `</#` and `>` stay
     * literal - the same split `renderHeadingRef()` makes.
     *
     * SCANNED, NOT ANCHORED. PART 12 §1a coalesces adjacent runs, so the marker
     * is usually in the middle of a longer text node rather than alone in one.
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
        // PART 11 §8a M1b decides those three on the EMITTED LINE, which only
        // resolveNarrowedEscapes() can see. `*` keeps M1 unconditionally (M1a),
        // and every other metacharacter keeps M1 as written (M1c).
        $escaped = preg_replace_callback(
            '/([\\\\`*_\[\]#])/',
            fn (array $m): string => $this->narrowedSentinels[$m[1]] ?? '\\' . $m[1],
            $text,
        ) ?? $text;

        // PART 11 SS8a M1e: a `<` is escaped only where the emitted line would
        // read it as markup - before an ASCII letter, `/`, `!` or `?`, the four
        // things that open raw HTML. Everything else is inert, and so is `>`
        // mid-line; at line start `>` is a block quote marker M1 already covers.
        //
        // A BACKSLASH, not an entity. This wrote the two entities
        // unconditionally with no clause behind it (markup-carve/carve#1148),
        // and that is precisely because an entity is not the operation the
        // section describes: M2 and M3 protect a character so it survives as
        // itself, and an entity replaces it instead. Escaping the `<` alone
        // suffices - a tag that cannot open cannot be closed.
        //
        // AFTER the metacharacter pass, so the backslash this inserts is not
        // itself escaped by it.
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
     *
     * A CommonMark consumer decodes character references inside a link
     * destination, so `&#106;avascript:alert1` reaches the browser as
     * `javascript:alert1` - a scheme the probe never saw, because the probe
     * reads the authored bytes. `&#x6A;` and `javascript&colon;alert1` are the
     * same trick (the second hides the colon, so there is no scheme to find at
     * all).
     *
     * Escaping the ampersand rather than percent-encoding it is what keeps this
     * honest: percent-encoding `&` would corrupt every legitimate query string,
     * while `&amp;` decodes back to `&` in the consumer, so the URL it resolves
     * is byte-for-byte the one probed here. It also stops the consumer from
     * silently rewriting an authored `&#106;` into `j`. An ampersand that opens
     * nothing (`?a=1&b=2`) is left exactly as authored.
     *
     * The three forms a consumer decodes are `&#DIGITS;`, `&#xHEXDIGITS;` and
     * `&NAME;`. An unknown NAME counts too - a consumer leaves it alone either
     * way, so escaping it changes nothing a reader sees, and guessing which
     * names are known would be a second denylist to keep in step with three
     * engines.
     *
     * The digit bound is 8, one more than the 7 CommonMark allows, so every
     * reference a conformant consumer decodes is covered. It is deliberately
     * the same number carve-js and carve-rs use: the emitted bytes of this
     * target are cross-engine pinned, and a wider bound in one engine would
     * show up as a divergence on an input with a longer digit run.
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
