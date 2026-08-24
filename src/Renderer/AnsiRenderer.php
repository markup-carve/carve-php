<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

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
use MarkupCarve\Carve\Node\Block\Section;
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
use MarkupCarve\Carve\Renderer\Utility\ConsumedAbbreviationDefinitions;
use MarkupCarve\Carve\Renderer\Utility\DerivedLabelTrait;
use MarkupCarve\Carve\Renderer\Utility\DocumentSentinels;
use MarkupCarve\Carve\Util\StringUtil;

/**
 * Renders AST to ANSI-formatted terminal output
 *
 * Produces colored, styled text suitable for display in terminals
 * that support ANSI escape codes.
 */
class AnsiRenderer implements RendererInterface
{
    use AbbreviationBudgetTrait;
    use DerivedLabelTrait;

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

    protected SmartTypographyMode $smartTypography = SmartTypographyMode::Glyph;

    /**
     * Whether smart typography renders as its glyph or as the source run the
     * author typed.
     *
     * The same switch the HTML and Markdown renderers carry. It was missing
     * here, so a caller who turned smart typography off still got glyphs on
     * this target (carve#560).
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

    // ANSI escape codes
    /**
     * @var string
     */
    public const RESET = "\033[0m";

    /**
     * @var string
     */
    public const BOLD = "\033[1m";

    /**
     * @var string
     */
    public const DIM = "\033[2m";

    /**
     * @var string
     */
    public const ITALIC = "\033[3m";

    /**
     * @var string
     */
    public const UNDERLINE = "\033[4m";

    /**
     * @var string
     */
    public const STRIKETHROUGH = "\033[9m";

    /**
     * @var string
     */
    public const REVERSE = "\033[7m";

    protected SoftBreakMode $softBreakMode = SoftBreakMode::Space;

    /**
     * Set how soft breaks are rendered.
     */
    public function setSoftBreakMode(SoftBreakMode $mode): self
    {
        $this->softBreakMode = $mode;

        return $this;
    }

    /**
     * Get the current soft break mode.
     */
    public function getSoftBreakMode(): SoftBreakMode
    {
        return $this->softBreakMode;
    }

    // Foreground colors
    /**
     * @var string
     */
    public const FG_BLACK = "\033[30m";

    /**
     * @var string
     */
    public const FG_RED = "\033[31m";

    /**
     * @var string
     */
    public const FG_GREEN = "\033[32m";

    /**
     * @var string
     */
    public const FG_YELLOW = "\033[33m";

    /**
     * @var string
     */
    public const FG_BLUE = "\033[34m";

    /**
     * @var string
     */
    public const FG_MAGENTA = "\033[35m";

    /**
     * @var string
     */
    public const FG_CYAN = "\033[36m";

    /**
     * @var string
     */
    public const FG_WHITE = "\033[37m";

    /**
     * @var string
     */
    public const FG_BRIGHT_BLACK = "\033[90m";

    /**
     * @var string
     */
    public const FG_BRIGHT_RED = "\033[91m";

    /**
     * @var string
     */
    public const FG_BRIGHT_GREEN = "\033[92m";

    /**
     * @var string
     */
    public const FG_BRIGHT_YELLOW = "\033[93m";

    /**
     * @var string
     */
    public const FG_BRIGHT_BLUE = "\033[94m";

    /**
     * @var string
     */
    public const FG_BRIGHT_MAGENTA = "\033[95m";

    /**
     * @var string
     */
    public const FG_BRIGHT_CYAN = "\033[96m";

    /**
     * @var string
     */
    public const FG_BRIGHT_WHITE = "\033[97m";

    // Background colors
    /**
     * @var string
     */
    public const BG_BLACK = "\033[40m";

    /**
     * @var string
     */
    public const BG_RED = "\033[41m";

    /**
     * @var string
     */
    public const BG_GREEN = "\033[42m";

    /**
     * @var string
     */
    public const BG_YELLOW = "\033[43m";

    /**
     * @var string
     */
    public const BG_BLUE = "\033[44m";

    /**
     * @var string
     */
    public const BG_MAGENTA = "\033[45m";

    /**
     * @var string
     */
    public const BG_CYAN = "\033[46m";

    /**
     * @var string
     */
    public const BG_WHITE = "\033[47m";

    /**
     * @var string
     */
    public const BG_BRIGHT_BLACK = "\033[100m";

    // Unicode box drawing characters
    /**
     * @var string
     */
    public const BOX_HORIZONTAL = '─';

    /**
     * @var string
     */
    public const BOX_VERTICAL = '│';

    /**
     * @var string
     */
    public const BOX_TOP_LEFT = '┌';

    /**
     * @var string
     */
    public const BOX_TOP_RIGHT = '┐';

    /**
     * @var string
     */
    public const BOX_BOTTOM_LEFT = '└';

    /**
     * @var string
     */
    public const BOX_BOTTOM_RIGHT = '┘';

    /**
     * @var string
     */
    public const BOX_T_DOWN = '┬';

    /**
     * @var string
     */
    public const BOX_T_UP = '┴';

    /**
     * @var string
     */
    public const BOX_T_RIGHT = '├';

    /**
     * @var string
     */
    public const BOX_T_LEFT = '┤';

    /**
     * @var string
     */
    public const BOX_CROSS = '┼';

    // List markers
    /**
     * @var string
     */
    public const BULLET = '•';

    /**
     * @var string
     */
    public const CHECKBOX_UNCHECKED = '☐';

    /**
     * @var string
     */
    public const CHECKBOX_CHECKED = '☑';

    protected int $listDepth = 0;

    protected int $blockQuoteDepth = 0;

    protected int $terminalWidth = 80;

    protected bool $useColors = true;

    protected bool $useUnicode = true;

    protected HeadingIdTracker $headingIdTracker;

    protected int $renderDepth = 0;

    /**
     * @var array<int, int>
     */
    protected array $orderedListCounters = [];

    public function __construct(int $terminalWidth = 80, bool $useColors = true, bool $useUnicode = true)
    {
        $this->terminalWidth = $terminalWidth;
        $this->useColors = $useColors;
        $this->useUnicode = $useUnicode;
        $this->headingIdTracker = new HeadingIdTracker();
    }

    /**
     * Set terminal width for wrapping
     */
    public function setTerminalWidth(int $width): self
    {
        $this->terminalWidth = $width;

        return $this;
    }

    /**
     * Enable or disable ANSI colors
     */
    public function setUseColors(bool $useColors): self
    {
        $this->useColors = $useColors;

        return $this;
    }

    /**
     * Enable or disable Unicode characters (bullets, box drawing)
     */
    public function setUseUnicode(bool $useUnicode): self
    {
        $this->useUnicode = $useUnicode;

        return $this;
    }

    /**
     * Every abbreviation definition the author wrote, dimmed.
     *
     * PART 11 §10a: a definition NOTHING references is still emitted by this
     * target - see the note in MarkdownRenderer. They live on the document
     * rather than in `children` here, so this renderer places them itself.
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
     * The first private-use code point this target's definition placeholder
     * prefers.
     *
     * @var int
     */
    protected const ABBREVIATION_SENTINEL_FIRST = 0xE100;

    /**
     * The expansions this render has actually emitted, keyed per §10f.
     *
     * @var array<string, true>
     */
    protected array $emittedAbbreviationExpansions = [];

    /**
     * One entry per definition line whose fate is still open, in the order the
     * placeholders were written.
     *
     * @var array<int, array{key: string, line: string}>
     */
    protected array $deferredAbbreviationDefinitions = [];

    /**
     * The character the placeholders are wrapped in, or '' outside render().
     */
    protected string $abbreviationDefinitionSentinel = '';

    /**
     * Definitions the document holds only as map entries (the API path, see
     * Document::getAbbreviationDefinitionsNotInTree).
     *
     * Read AFTER the body, so §10f is answered here from what was emitted
     * rather than deferred.
     */
    protected function renderResidualAbbreviationDefinitions(Document $document): string
    {
        $lines = [];
        foreach ($document->getAbbreviationDefinitionsNotInTree() as $definition) {
            $key = ConsumedAbbreviationDefinitions::key($definition['abbr'], $definition['expansion']);
            if (isset($this->emittedAbbreviationExpansions[$key])) {
                continue;
            }
            $lines[] = $this->style(
                '*[' . $this->stripControls($definition['abbr']) . ']: '
                    . $this->stripControls($definition['expansion']),
                self::DIM,
            );
        }

        return $lines === [] ? '' : implode("\n\n", $lines) . "\n";
    }

    /**
     * PART 11 §10f T2: this target DROPS the line of a definition whose
     * expansion it emits, and keeps every other one.
     *
     * The line goes because the same words would otherwise appear twice - once
     * as `*[TERM]: expansion` and once as the `TERM (expansion)` this target has
     * always printed at the occurrence. Where the expansion reaches no target
     * the line is the only copy of the author's text, so it stays: that covers a
     * term nothing references (§10a), a term an authored `abbr` outranks
     * (PART 9 §9), a definition a later one shadowed (PART 9R R3), and the
     * degraded case where the §25 expansion budget kept the occurrence from
     * printing.
     *
     * WRITTEN AS A PLACEHOLDER, resolved once the body is finished, because the
     * definition can be authored ABOVE the occurrence that consumes it and this
     * target emits it where the author put it (carve-php#708). Dispatched
     * outside render() there is no placeholder to resolve, so the line is
     * written as §10a has it.
     */
    protected function renderAbbreviationDefinition(AbbreviationDefinition $child): string
    {
        $line = $this->style(
            '*[' . $this->stripControls($child->getAbbr()) . ']: '
                . $this->stripControls($child->getExpansion()),
            self::DIM,
        );

        if ($this->abbreviationDefinitionSentinel === '') {
            return $line . "\n\n";
        }

        $index = count($this->deferredAbbreviationDefinitions);
        $this->deferredAbbreviationDefinitions[$index] = [
            'key' => ConsumedAbbreviationDefinitions::key($child->getAbbr(), $child->getExpansion()),
            'line' => $line,
        ];

        return $this->placeholderFor($index) . "\n\n";
    }

    /**
     * The placeholder standing in for one deferred definition line.
     */
    protected function placeholderFor(int $index): string
    {
        return $this->abbreviationDefinitionSentinel . $index . $this->abbreviationDefinitionSentinel;
    }

    /**
     * Write each deferred definition line, or nothing where §10f drops it.
     *
     * The SEPARATORS around a placeholder are left alone and the blank-line
     * normalisation below collapses what a dropped line leaves behind. Replacing
     * the separator too would need the placeholder to survive a container
     * verbatim, and a block quote rewrites the start of every line it holds.
     */
    protected function resolveAbbreviationDefinitions(string $text): string
    {
        foreach ($this->deferredAbbreviationDefinitions as $index => $deferred) {
            $emitted = isset($this->emittedAbbreviationExpansions[$deferred['key']]);
            $text = str_replace(
                $this->placeholderFor($index),
                $emitted ? '' : $deferred['line'],
                $text,
            );
        }

        return $text;
    }

    public function render(Document $document): string
    {
        $this->headingIdTracker->reset();
        $this->resetExpansionBudgetForDocument($document);
        (new CrossReferenceResolver())->resolve($document, $this->headingIdTracker);
        // PART 11 §10f: a definition line can be written ABOVE the occurrence
        // that consumes it, so whether to write it is not answerable when it is
        // reached. It goes out as a placeholder and is resolved below, once the
        // body has said which expansions it actually emitted.
        $this->emittedAbbreviationExpansions = [];
        $this->deferredAbbreviationDefinitions = [];
        $this->abbreviationDefinitionSentinel = DocumentSentinels::pick(
            DocumentSentinels::collectStrings($document),
            1,
            self::ABBREVIATION_SENTINEL_FIRST,
        )[0];

        // The definition renders WHERE IT WAS WRITTEN, from its node, because the
        // dispatch has an arm for it. This used to place the whole set at one end
        // of the body, chosen by `hasAbbreviationsBeforeBody()` - two positions,
        // which is one fewer than a document can express, so a definition
        // authored BETWEEN two blocks moved to an end. carve-js and carve-rs both
        // keep it in place, and this node exists precisely so this renderer can
        // too (carve-php#708).
        $output = $this->resolveAbbreviationDefinitions($this->renderChildren($document));
        $residual = $this->renderResidualAbbreviationDefinitions($document);
        if ($residual !== '') {
            $output = $document->hasAbbreviationsBeforeBody()
                ? $residual . "\n" . $output
                : $output . "\n" . $residual;
        }

        // Normalize multiple blank lines
        $output = preg_replace("/\n{3,}/", "\n\n", $output) ?? $output;

        $output = trim($output) . "\n";

        // The internal non-breaking-space placeholder (U+E000) collapses to an
        // ordinary space in terminal output. Done after trimming so placeholder-
        // derived leading indentation survives; a literal U+00A0 is left intact.
        return str_replace("\u{E000}", ' ', $output);
    }

    protected function renderNode(Node $node): string
    {
        if ($this->renderDepth >= self::MAX_RENDER_DEPTH) {
            throw new RenderDepthExceededException(self::MAX_RENDER_DEPTH, 'ANSI');
        }

        $this->renderDepth++;
        try {
            // An unresolved reference renders as the source the author
            // wrote, never as a link (PART 12 section 3a).
            // PART 9 §23: a comment LINE publishes nothing, so the stored
            // source is emptied of them here rather than in the stored value -
            // `rawRef` stays the authored source verbatim for the canonical
            // writer (PART 12 §3a, carve-php#1417).
            $rawReference = UnresolvedReference::renderedSourceOf($node);

            return match (true) {
                $node instanceof Document => $this->renderChildren($node),
                $node instanceof AbbreviationDefinition
                => $this->renderAbbreviationDefinition($node),
                $node instanceof Paragraph => $this->renderParagraph($node),
                $node instanceof Heading => $this->renderHeading($node),
                $node instanceof CodeBlock => $this->renderCodeBlock($node),
                $node instanceof Caption => $this->renderCaption($node),
                $node instanceof Comment => '', // Skip comments
                // PART 12 §18. The definition renders nothing where it sits
                // on every target; its entry renders in the references list the
                // Citations extension builds. Without this arm the default
                // branch renders the entry's children into the flow, which is
                // rendered output moving on a tree change that must not move it
                // (markup-carve/carve#1276).
                $node instanceof CitationDefinition => '',
                $node instanceof FigureGroup => $this->renderFigureGroup($node),
                $node instanceof Figure => $this->renderFigure($node),
                $node instanceof RawBlock => $this->renderRawBlock($node),
                $node instanceof Section => $this->renderChildren($node),
                $node instanceof BlockQuote => $this->renderBlockQuote($node),
                $node instanceof ListBlock => $this->renderList($node),
                $node instanceof ListItem => $this->renderListItem($node),
                $node instanceof DefinitionList => $this->renderDefinitionList($node),
                $node instanceof DefinitionTerm => $this->renderDefinitionTerm($node),
                $node instanceof DefinitionDescription => $this->renderDefinitionDescription($node),
                $node instanceof ThematicBreak => $this->renderThematicBreak(),
                $node instanceof Div => $this->renderDiv($node),
                $node instanceof Table => $this->renderTable($node),
                $node instanceof LineBlock => $this->renderLineBlock($node),
                $node instanceof Footnote => $this->renderFootnote($node),
                $node instanceof Text => $this->stripControls($node->getContent()),
                $node instanceof EscapedText => $this->stripControls($node->getContent()),
                $node instanceof Abbreviation => $this->renderAbbreviation($node),
                $node instanceof Emphasis => $this->renderEmphasis($node),
                $node instanceof Strong => $this->renderStrong($node),
                $node instanceof Underline => $this->renderUnderline($node),
                $node instanceof Strike => $this->renderStrike($node),
                $node instanceof Code => $this->renderCode($node),
                $rawReference !== null => $this->stripControls($rawReference),
                $node instanceof Mention => $this->renderMention($node),
                $node instanceof Link => $this->renderLink($node),
                // A BLOCK-position image needs the separator a paragraph would
                // have added; without it the image ran straight into whatever
                // followed (markup-carve/carve-rs#692). Decided by POSITION,
                // not by class, the same test the Markdown renderer uses: this
                // match covers inline nodes too, and a bare `instanceof Image`
                // arm would split every inline image across three lines.
                $node instanceof Image && $this->isBlockPositionImage($node)
                => $this->renderImage($node) . "\n\n",
                $node instanceof Image => $this->renderImage($node),
                $node instanceof HardBreak => "\n",
                $node instanceof SoftBreak => $this->softBreakMode === SoftBreakMode::Space ? ' ' : "\n",
                $node instanceof Superscript => $this->renderSuperscript($node),
                $node instanceof Subscript => $this->renderSubscript($node),
                $node instanceof Highlight => $this->renderHighlight($node),
                $node instanceof Insert => $this->renderInsert($node),
                $node instanceof Delete => $this->renderDelete($node),
                $node instanceof Substitution => $this->renderSubstitution($node),
                $node instanceof CriticComment => $this->stripControls($node->getContent()),
                $node instanceof Span => $this->renderSpan($node),
                $node instanceof Math => $this->renderMath($node),
                $node instanceof Symbol => $this->renderSymbol($node),
                $node instanceof InlineFootnote => '(' . $this->renderChildren($node) . ')',
                $node instanceof FootnoteRef && $node->isUnresolved()
                => $this->stripControls('[^' . $node->getLabel() . ']'),
                $node instanceof FootnoteRef => $this->renderFootnoteRef($node),
                $node instanceof HeadingRef => $this->renderHeadingRef($node),
                $node instanceof CaptionNumber => $node->getNumber() === null ? '#' : (string)$node->getNumber(),
                $node instanceof RawInline => '', // Skip raw inline
                // §27: always emitted (unlike raw passthrough above). It is
                // prose, not code, so it carries no code styling.
                $node instanceof LiteralInline => $this->stripControls($node->getContent()),
                $node instanceof RawText => $this->stripControls($node->getContent()),
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
        // Exact match first, then a case-insensitive fallback (matches HtmlRenderer).
        $id = $this->headingIdTracker->findIdCaseInsensitive($target);
        $label = $id === null ? null : $this->headingIdTracker->getTextForId($id, $this->smartTypography);
        if ($id === null || $label === null) {
            return '</#' . $this->stripControls($target) . '>';
        }

        // Same expansion budget the abbreviation arm spends, degrading to the
        // authored target (carve-php#1061). See AbbreviationBudgetTrait.
        //
        // THE LABEL IS THE HEADING'S INLINE NODES, rendered by THIS target
        // (PART 9R R4, markup-carve/carve#957), so a heading's emphasis reaches
        // the label as this target's own styling rather than as bare text. A
        // caption id has no heading behind it and keeps the composed string.
        $nodes = $this->headingIdTracker->getLabelNodesForId($id);
        $rendered = $nodes === null
            ? $this->stripControls($label)
            : $this->renderDerivedLabel($nodes);
        if (!$this->chargeExpansion($rendered)) {
            $rendered = $this->stripControls($target);
        }

        return $this->style($rendered, self::UNDERLINE . self::FG_BLUE);
    }

    protected function renderChildren(Node $node): string
    {
        $output = '';
        foreach ($node->getChildren() as $child) {
            $output .= $this->renderNode($child);
        }

        return $output;
    }

    /**
     * NO BLOCKQUOTE PREFIXING HERE, and no special case for a lone image.
     *
     * This method used to do both: it drew the quote bar, and it STRIPPED that
     * bar again from a paragraph whose only content was one image. Neither
     * belongs here. The strip was removed first, as a one-engine defect
     * (markup-carve/carve-php#1691), because a promoted image is an Image child
     * of the quote that never reaches this method at all, so the special case
     * only ever fired on the spelling that should keep the bar.
     *
     * The drawing went with markup-carve/carve#1689: the bar reports
     * CONTAINMENT, not node kind, so renderBlockQuote() carries it for
     * everything the quote contains and a paragraph is not a special case.
     * Asking each block's own method to opt in is what left a heading, a code
     * block, a table and a promoted image with no bar at all, and what put a
     * quoted list's bar to the RIGHT of its bullet.
     */
    protected function renderParagraph(Paragraph $node): string
    {
        return $this->renderChildren($node) . "\n\n";
    }

    /**
     * A lone image is a block-level image node, so it takes the block
     * separator. An image inside a paragraph or another inline is inline.
     */
    protected function isBlockPositionImage(Image $node): bool
    {
        $parent = $node->getParent();

        return $parent !== null
            && !$parent instanceof Paragraph
            && !$parent instanceof Heading
            && !$parent instanceof InlineNode;
    }

    protected function renderHeading(Heading $node): string
    {
        $level = $node->getLevel();
        $content = $this->renderChildren($node);

        // Color based on level
        $color = match ($level) {
            1 => self::FG_BRIGHT_MAGENTA,
            2 => self::FG_BRIGHT_CYAN,
            3 => self::FG_BRIGHT_BLUE,
            4 => self::FG_BRIGHT_GREEN,
            5 => self::FG_BRIGHT_YELLOW,
            // Levels 1-5 are all BRIGHT variants, so level 6 is too. Plain white
            // here broke the engine's own series and was the only heading colour
            // the other two engines disagreed with (carve#352, corpus 02).
            default => self::FG_BRIGHT_WHITE,
        };

        $styled = $this->style($content, self::BOLD . $color);

        // Add underline for h1 and h2
        if ($level <= 2) {
            $underlineChar = $level === 1 ? '═' : '─';
            if (!$this->useUnicode) {
                $underlineChar = $level === 1 ? '=' : '-';
            }
            $underline = str_repeat($underlineChar, StringUtil::visibleWidth($content));
            $styled .= "\n" . $this->style($underline, $color);
        }

        return $styled . "\n\n";
    }

    protected function renderCodeBlock(CodeBlock $node): string
    {
        $content = $this->stripControls($node->getContent());
        $lang = $node->getLanguage();
        if ($lang !== null) {
            $lang = $this->stripControls($lang);
        }

        // Drop the content's TERMINATOR only. A bare `rtrim` also takes the
        // trailing space on the block's last line and every blank line at its
        // end - both of which are VERBATIM CONTENT that this engine's HTML,
        // plain-text, Markdown and canonical targets all keep, and that the
        // corpus HTML pins (`<pre><code>abc \n</code></pre>`). Only the ANSI
        // target dropped them, so the same code block rendered two ways out of
        // one engine (corpus 268-trailing-whitespace-on-a-content-line-is-
        // dropped-9).
        // The condition is on the CONTENT, not on the split. `explode` always
        // returns at least one element, so testing whether the last one is
        // empty cannot tell an emptied code block - content `''`, which splits
        // to one empty line and must RENDER as one empty line, the way carve-js
        // and carve-rs render it - from a block whose terminator left a
        // trailing empty element. Only the second has a terminator to drop
        // (corpus 276-a-fence-opened-on-a-list-marker-line-body-below-the-
        // content-column and three siblings).
        $lines = explode("\n", $content);
        if (str_ends_with($content, "\n")) {
            array_pop($lines);
        }
        $output = '';

        // PART 11 §10e T1: a fence header (`"src/app.js"`) and a grouping label
        // (`[Node]`) are authored text, and they RENDER THE WAY A DIV'S ALREADY
        // DO - a bold standalone line each above the block, the title always
        // before the label. renderDiv() below is that shape; a code fence
        // carries the same two tokens in the same two slots of its info string,
        // so it renders them the same way.
        //
        // Folding them into the rule line instead was measured and rejected by
        // the clause: that line exists ONLY when the fence has a language, so a
        // titled fence without one would have needed a header invented for it,
        // and a fence carrying both tokens would have needed a separator
        // invented too. The div's shape needs neither. So the language keeps the
        // rule line to itself, which is the slot this target already gave it.
        $fenceHeader = $node->getHeader();
        if ($fenceHeader !== null && $fenceHeader !== '') {
            $output .= $this->style($this->stripControls($fenceHeader), self::BOLD) . "\n\n";
        }
        $fenceLabel = $node->getLabel();
        if ($fenceLabel !== null && $fenceLabel !== '') {
            $output .= $this->style($this->stripControls($fenceLabel), self::BOLD) . "\n\n";
        }

        if ($lang !== null && $lang !== '') {
            $header = $this->useUnicode
                ? self::BOX_TOP_LEFT . str_repeat(self::BOX_HORIZONTAL, 2) . ' ' . $lang . ' '
                : '--- ' . $lang . ' ';
            $output .= $this->style($header, self::DIM) . "\n";
        }

        // Code content with background
        foreach ($lines as $line) {
            $styledLine = $this->style('  ' . $line, self::FG_BRIGHT_WHITE);
            $output .= $styledLine . "\n";
        }

        return $output . "\n";
    }

    protected function renderBlockQuote(BlockQuote $node): string
    {
        // The bar reports CONTAINMENT, not node kind (markup-carve/carve#1689):
        // everything the quote contains carries it, so the ANSI reader is never
        // told a block was unquoted where the HTML says it was. Prefixing here,
        // once, rather than in each child's own method is what makes that true
        // for every block kind - including the ones no method ever opted in for.
        $this->blockQuoteDepth++;
        $content = $this->renderChildren($node);
        $this->blockQuoteDepth--;

        return $this->prefixLines($content, $this->getBlockQuoteBar());
    }

    protected function getBlockQuoteBar(): string
    {
        $bar = $this->useUnicode ? '│' : '|';

        return $this->style($bar, self::FG_CYAN . self::DIM) . ' ';
    }

    /**
     * Prefix every NON-EMPTY line. A quote's rendered body carries the block
     * separator ("\n\n") between its children and after the last one, and
     * those blank lines stay bare - a bar on a blank line would draw a gutter
     * through the space BETWEEN blocks and past the end of the quote. Skipping
     * them reproduces exactly what prefixing inside renderParagraph() got by
     * running before the separator was appended, and it composes for nesting:
     * an inner quote has already prefixed its own lines, so the outer pass adds
     * a second bar to the same lines and leaves the same blanks alone.
     */
    protected function prefixLines(string $content, string $prefix): string
    {
        $lines = explode("\n", $content);
        $prefixed = [];
        foreach ($lines as $line) {
            $prefixed[] = $line === '' ? $line : $prefix . $line;
        }

        return implode("\n", $prefixed);
    }

    protected function renderList(ListBlock $node): string
    {
        $this->listDepth++;

        if ($node->getListType() === ListBlock::TYPE_ORDERED) {
            $this->orderedListCounters[$this->listDepth] = $node->getStart();
        }

        $output = $this->renderChildren($node);
        $this->listDepth--;

        if ($this->listDepth === 0) {
            $output .= "\n";
        }

        return $output;
    }

    protected function renderListItem(ListItem $node): string
    {
        $indent = str_repeat('  ', $this->listDepth - 1);
        $parent = $node->getParent();

        if ($parent instanceof ListBlock && $parent->getListType() === ListBlock::TYPE_ORDERED) {
            $num = $this->orderedListCounters[$this->listDepth] ?? 1;
            $marker = $this->style($num . '.', self::FG_YELLOW);
            $this->orderedListCounters[$this->listDepth] = $num + 1;
        } else {
            $bullet = $this->useUnicode ? self::BULLET : '*';
            $marker = $this->style($bullet, self::FG_CYAN);
        }

        $content = trim($this->renderChildren($node));

        // Handle task list items
        if ($node->isTask()) {
            $checkbox = $node->getChecked()
                ? $this->style($this->useUnicode ? self::CHECKBOX_CHECKED : '[x]', self::FG_GREEN)
                : $this->style($this->useUnicode ? self::CHECKBOX_UNCHECKED : '[ ]', self::FG_BRIGHT_BLACK);
            $marker = $checkbox;
        }

        return $indent . $marker . ' ' . $content . "\n";
    }

    protected function renderDefinitionList(DefinitionList $node): string
    {
        return $this->renderChildren($node) . "\n";
    }

    protected function renderDefinitionTerm(DefinitionTerm $node): string
    {
        $content = $this->renderChildren($node);

        return $this->style($content, self::BOLD . self::FG_YELLOW) . "\n";
    }

    protected function renderDefinitionDescription(DefinitionDescription $node): string
    {
        $content = trim($this->renderChildren($node));

        return '  ' . $content . "\n";
    }

    protected function renderThematicBreak(): string
    {
        $char = $this->useUnicode ? '─' : '-';
        $line = str_repeat($char, min(40, $this->terminalWidth - 4));

        return $this->style($line, self::DIM) . "\n\n";
    }

    protected function renderDiv(Div $node): string
    {
        $body = $this->renderChildren($node);
        $prefix = '';
        // Preserve an admonition's quoted opener header as a leading bold line.
        $title = $node->getHeader();
        if (is_string($title)) {
            $prefix .= $this->style($this->renderTitleInlineNodes($node->getHeaderNodes()), self::BOLD) . "\n\n";
        }
        // PROPOSAL (graceful degradation): a grouping `[label]` (grammar PART 9
        // §12) is normally consumed by a group extension (e.g. tabs). When no
        // extension replaced this div, surface the label as a leading bold line
        // so it is not silently dropped. Title (if any) renders first.
        $label = $node->getLabel();
        if ($label !== null && $label !== '') {
            $prefix .= $this->style($this->stripControls($label), self::BOLD) . "\n\n";
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
        // First pass: calculate column widths
        $colWidths = [];
        $rows = [];

        $layout = TableLayout::expand(
            $node,
            fn (TableCell $cell): array => [
                'content' => trim($this->renderChildren($cell)),
                'isHeader' => $cell->isHeader(),
            ],
        );

        foreach ($layout['rows'] as $row) {
            $cells = [];
            foreach ($row['cells'] as $colIndex => $cell) {
                $isGenuine = is_array($cell) && isset($cell['content']) && is_string($cell['content']);
                $content = $isGenuine ? $cell['content'] : '';
                $width = StringUtil::visibleWidth($content);
                $colWidths[$colIndex] = max($colWidths[$colIndex] ?? 0, $width);
                $cells[] = [
                    'content' => $content,
                    'isHeader' => $row['isHeader'],
                ];
            }
            $rows[] = $cells;
        }

        // Second pass: render table
        $output = '';
        $isFirstRow = true;
        $headerRendered = false;

        foreach ($rows as $rowIndex => $cells) {
            // Top border for first row
            if ($isFirstRow) {
                $output .= $this->renderTableBorder($colWidths, 'top');
                $isFirstRow = false;
            }

            // Render row
            $output .= $this->renderTableRow($cells, $colWidths);

            // Separator after header
            if (isset($cells[0]['isHeader']) && $cells[0]['isHeader'] && !$headerRendered) {
                $output .= $this->renderTableBorder($colWidths, 'middle');
                $headerRendered = true;
            }
        }

        // Bottom border
        $output .= $this->renderTableBorder($colWidths, 'bottom');

        // Table caption
        if ($node->hasCaption()) {
            /** @var \MarkupCarve\Carve\Node\Block\Caption $caption */
            $caption = $node->getCaption();
            $output .= $this->renderCaption($caption);
        }

        return $output . "\n";
    }

    /**
     * @param array<int, int> $colWidths
     * @param string $position
     */
    protected function renderTableBorder(array $colWidths, string $position): string
    {
        if (!$this->useUnicode) {
            $total = array_sum($colWidths) + (count($colWidths) * 3) + 1;

            return '+' . str_repeat('-', $total - 2) . "+\n";
        }

        $left = match ($position) {
            'top' => self::BOX_TOP_LEFT,
            'middle' => self::BOX_T_RIGHT,
            'bottom' => self::BOX_BOTTOM_LEFT,
            default => self::BOX_T_RIGHT,
        };

        $right = match ($position) {
            'top' => self::BOX_TOP_RIGHT,
            'middle' => self::BOX_T_LEFT,
            'bottom' => self::BOX_BOTTOM_RIGHT,
            default => self::BOX_T_LEFT,
        };

        $cross = match ($position) {
            'top' => self::BOX_T_DOWN,
            'middle' => self::BOX_CROSS,
            'bottom' => self::BOX_T_UP,
            default => self::BOX_CROSS,
        };

        $parts = [];
        foreach ($colWidths as $width) {
            $parts[] = str_repeat(self::BOX_HORIZONTAL, $width + 2);
        }

        return $this->style($left . implode($cross, $parts) . $right, self::DIM) . "\n";
    }

    /**
     * @param array<int, array{content: string, isHeader: bool}> $cells
     * @param array<int, int> $colWidths
     */
    protected function renderTableRow(array $cells, array $colWidths): string
    {
        $separator = $this->useUnicode ? self::BOX_VERTICAL : '|';
        $styledSep = $this->style($separator, self::DIM);

        $parts = [];
        foreach ($cells as $index => $cell) {
            $width = $colWidths[$index] ?? 0;
            $padding = $width - StringUtil::visibleWidth($cell['content']);
            $content = $cell['content'] . str_repeat(' ', $padding);

            if ($cell['isHeader']) {
                $content = $this->style($cell['content'] . str_repeat(' ', $padding), self::BOLD);
            }

            $parts[] = ' ' . $content . ' ';
        }

        return $styledSep . implode($styledSep, $parts) . $styledSep . "\n";
    }

    protected function renderLineBlock(LineBlock $node): string
    {
        return $this->renderChildren($node) . "\n";
    }

    protected function renderFootnote(Footnote $node): string
    {
        $label = $this->stripControls($node->getLabel());
        $content = trim($this->renderChildren($node));
        // The marker as written (PART 11 §10a): the caret is the construct.
        $marker = $this->style('[^' . $label . ']', self::FG_CYAN . self::DIM);

        return $marker . ' ' . $content . "\n";
    }

    protected function renderEmphasis(Emphasis $node): string
    {
        return $this->style($this->renderChildren($node), self::ITALIC);
    }

    protected function renderStrong(Strong $node): string
    {
        // The COMBINED bold-italic form is one construct, so it gets one style run
        // and one reset. Rendering it as nested strong-around-emphasis emitted a
        // reset per level, and the second is redundant since a reset clears every
        // attribute -- which is why the output was never visibly wrong and this
        // surfaced only as a cross-engine divergence. carve-rs carries bold-italic
        // as a single kind and always emitted one (carve#352, corpus 01-emphasis
        // and both 128-bold-italic cases).
        $children = $node->getChildren();
        $inner = $children[0] ?? null;
        if ($node->isBoldItalic() && count($children) === 1 && $inner instanceof Emphasis) {
            return $this->style($this->renderChildren($inner), self::BOLD . self::ITALIC);
        }

        return $this->style($this->renderChildren($node), self::BOLD);
    }

    protected function renderCode(Code $node): string
    {
        $content = $this->stripControls($node->getContent());

        return $this->style($content, self::FG_BRIGHT_YELLOW);
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
        $url = $node->getDestination();

        // OSC 8 hyperlink support (for terminals that support it)
        // Format: \033]8;;URL\033\\TEXT\033]8;;\033\\
        $styled = $this->style($text, self::UNDERLINE . self::FG_BLUE);

        // The target is shown alongside the text only when the author wrote
        // something DIFFERENT from it. An autolink's visible text IS its target,
        // so there is nothing to add -- and for an email autolink the `mailto:`
        // was added by the parser, never written: the grammar's DISPLAY TEXT rule
        // says that scheme goes on the href only, so showing it to the reader put
        // it exactly where it must not appear (carve#352, corpus 03-links-5 and
        // 36-autolinks).
        $showTarget = $url !== null
            && $url !== ''
            && $url !== $text
            && !$node->isAutolink()
            && !str_starts_with($url, '#');
        if ($showTarget) {
            // PART 9 §25 binds every target that emits a resolvable URL, and this
            // parenthetical IS the destination: a terminal autolinks it and hands
            // the scheme to the OS handler on click, which is the same "deferred
            // by one step" the clause describes for Markdown. It printed
            // `javascript:` and the OS protocol-handler class verbatim, in all
            // three engines, where Markdown already blanked them (carve-php#867).
            //
            // Blanked rather than omitted: §25 says to emit an EMPTY value, and
            // the empty parenthetical distinguishes "withheld" from "the author
            // wrote none". `$showTarget` is decided from the AUTHORED destination
            // above, so a blanked one cannot change whether the parenthetical
            // appears - only what is in it.
            //
            // The HTML renderer's one implementation, not a copy: a local list of
            // four schemes in a writer is what let the OS-handler class through in
            // carve#385, and the Markdown writer's own sanitizer delegates here
            // for exactly that reason.
            $shown = HtmlRenderer::blankDangerousScheme($this->stripControls($url));
            $styled .= $this->style(' (' . $shown . ')', self::DIM);
        }

        return $styled;
    }

    protected function renderImage(Image $node): string
    {
        $alt = $this->stripControls($node->getAlt());
        $marker = $this->style('[img:', self::FG_MAGENTA);
        $altText = $alt !== '' ? ' ' . $alt : '';

        return $marker . $altText . $this->style(']', self::FG_MAGENTA);
    }

    protected function renderSuperscript(Superscript $node): string
    {
        $content = $this->renderChildren($node);
        // Use Unicode superscript characters if possible
        if ($this->useUnicode) {
            return $this->toSuperscript($content);
        }

        return '^' . $content;
    }

    protected function renderSubscript(Subscript $node): string
    {
        $content = $this->renderChildren($node);
        // Use Unicode subscript characters if possible
        if ($this->useUnicode) {
            return $this->toSubscript($content);
        }

        return '_' . $content;
    }

    protected function renderHighlight(Highlight $node): string
    {
        return $this->style($this->renderChildren($node), self::REVERSE . self::FG_YELLOW);
    }

    protected function renderInsert(Insert $node): string
    {
        return $this->style($this->renderChildren($node), self::FG_GREEN . self::UNDERLINE);
    }

    protected function renderDelete(Delete $node): string
    {
        return $this->style($this->renderChildren($node), self::STRIKETHROUGH . self::FG_RED);
    }

    protected function renderSubstitution(Substitution $node): string
    {
        return $this->style($this->stripControls($node->getOldText()), self::STRIKETHROUGH . self::FG_RED)
            . $this->style($this->stripControls($node->getNewText()), self::FG_GREEN . self::UNDERLINE);
    }

    protected function renderUnderline(Underline $node): string
    {
        return $this->style($this->renderChildren($node), self::UNDERLINE);
    }

    protected function renderStrike(Strike $node): string
    {
        return $this->style($this->renderChildren($node), self::STRIKETHROUGH);
    }

    protected function renderMath(Math $node): string
    {
        $content = $this->stripControls($node->getContent());

        return $this->style($content, self::FG_BRIGHT_MAGENTA);
    }

    protected function renderSymbol(Symbol $node): string
    {
        // A symbol renders as its literal `:name:` by default, matching the HTML
        // renderer, carve-js, and carve-rs (the HTML output keeps `:name:` too).
        // Emoji substitution is opt-in via an emoji map, not a built-in default,
        // so the ANSI renderer must not silently map names the other outputs do
        // not.
        return ':' . $this->stripControls($node->getName()) . ':';
    }

    protected function renderFootnoteRef(FootnoteRef $node): string
    {
        $label = $this->stripControls($node->getLabel());

        return $this->style('[' . $label . ']', self::FG_CYAN . self::BOLD);
    }

    protected function renderRawBlock(RawBlock $node): string
    {
        // Show raw blocks dimmed
        $format = $this->stripControls($node->getFormat());
        $content = $this->stripControls($node->getContent());

        return $this->style('[raw:' . $format . '] ' . $content, self::DIM) . "\n\n";
    }

    /**
     * Strip Unicode control characters from author-derived content (keeping tab
     * and newline) so attacker text cannot inject terminal escape / OSC
     * sequences (cursor moves, color resets, clipboard writes) into ANSI output.
     * The renderer's own styling escapes are added separately and are not
     * affected.
     */
    protected function stripControls(string $text): string
    {
        $text = (string)preg_replace('/(?!\x{0009}|\x{000A})\p{Cc}/u', '', $text);

        return str_contains($text, "\xE2")
            ? (string)preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text)
            : $text;
    }

    /**
     * A composite figure (grammar PART 11 §10g T2), same order as the plain
     * text target: the GROUP caption first with its number resolved, a blank
     * line, then each panel - its caption line, then its host's degradation -
     * with a blank line between panels. Captions carry this target's usual
     * caption styling.
     */
    protected function renderFigureGroup(FigureGroup $node): string
    {
        $output = '';
        $caption = $node->getCaption();
        if ($caption !== null) {
            $output .= $this->renderCaption($caption);
        }

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
                if ($panelCaption !== null) {
                    $output .= rtrim($this->renderCaption($panelCaption), "\n") . "\n";
                }
                $output .= rtrim($host, "\n") . "\n\n";
            } else {
                $output .= $this->renderNode($child);
            }
        }

        return $output;
    }

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
                // Render caption after content, styled as italic.
                $output = rtrim($output, "\n") . $sep . $this->renderCaption($child);
            } else {
                $output .= $this->renderNode($child);
            }
        }

        return $output;
    }

    protected function renderCaption(Caption $node): string
    {
        $content = trim($this->renderChildren($node));

        return $this->style($content, self::ITALIC . self::DIM) . "\n\n";
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

    /**
     * A span renders its children bare, EXCEPT for an authored `abbr`, which
     * outranks the document definition (markup-carve/carve#1127). ANSI has no
     * markup to carry a title, so the expansion is parenthetical text - the
     * same shape this target already uses, carrying the AUTHORED value
     * (markup-carve/carve#1176).
     */
    protected function renderSpan(Span $node): string
    {
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

        return $inner . $this->style(' (' . $this->stripControls($authored) . ')', self::DIM);
    }

    protected function renderAbbreviation(Abbreviation $node): string
    {
        $text = $this->renderChildren($node);

        // Inside a span carrying its own `abbr`, only the visible text
        // (markup-carve/carve#1127).
        if ($this->suppressAutomaticAbbreviation) {
            return $text;
        }

        // DoS guard: once the cumulative expansion bytes would exceed the
        // budget, degrade to plain key text (no parenthesized definition).
        if (!$this->chargeAbbreviationExpansion($node->getTitle())) {
            return $text;
        }

        // RECORDED HERE and nowhere earlier: this is the one place that knows
        // the expansion is going out, which is what §10f keys the definition
        // line's fate to.
        $this->emittedAbbreviationExpansions[ConsumedAbbreviationDefinitions::keyOf($node)] = true;

        $title = $this->stripControls($node->getTitle());

        // Show abbreviation with definition in parentheses
        return $text . $this->style(' (' . $title . ')', self::DIM);
    }

    /**
     * Apply ANSI styling if colors are enabled
     */
    protected function style(string $text, string $codes): string
    {
        if (!$this->useColors) {
            return $text;
        }

        return $codes . $text . self::RESET;
    }

    /**
     * Convert text to Unicode superscript characters
     */
    protected function toSuperscript(string $text): string
    {
        $map = [
            '0' => '⁰',
            '1' => '¹',
            '2' => '²',
            '3' => '³',
            '4' => '⁴',
            '5' => '⁵',
            '6' => '⁶',
            '7' => '⁷',
            '8' => '⁸',
            '9' => '⁹',
            '+' => '⁺',
            '-' => '⁻',
            '=' => '⁼',
            '(' => '⁽',
            ')' => '⁾',
            'n' => 'ⁿ',
            'i' => 'ⁱ',
        ];

        return strtr($text, $map);
    }

    /**
     * Convert text to Unicode subscript characters
     */
    protected function toSubscript(string $text): string
    {
        $map = [
            '0' => '₀',
            '1' => '₁',
            '2' => '₂',
            '3' => '₃',
            '4' => '₄',
            '5' => '₅',
            '6' => '₆',
            '7' => '₇',
            '8' => '₈',
            '9' => '₉',
            '+' => '₊',
            '-' => '₋',
            '=' => '₌',
            '(' => '₍',
            ')' => '₎',
        ];

        return strtr($text, $map);
    }
}
