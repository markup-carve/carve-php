<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Exception\RenderDepthExceededException;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\DefinitionDescription;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\DefinitionTerm;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\LineBlock;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Block\ThematicBreak;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\CaptionNumber;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\CriticComment;
use MarkupCarve\Carve\Node\Inline\Delete;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\InlineFootnote;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\LiteralInline;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Inline\RawInline;
use MarkupCarve\Carve\Node\Inline\RawText;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Substitution;
use MarkupCarve\Carve\Node\Inline\Symbol;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Inline\UnresolvedReference;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\Utility\EventDispatcherTrait;

/**
 * Renders AST to plain text
 *
 * Useful for:
 * - Search indexing
 * - SEO meta descriptions
 * - Plain text email fallbacks
 * - Word count / reading time estimation
 */
class PlainTextRenderer implements RendererInterface
{
    use EventDispatcherTrait;

    /**
     * Presentation renderers emit the resolved glyph; the Carve renderer emits
     * the author's source instead, so `fmt` reproduces the input.
     */
    protected function renderSmartPunctuation(SmartPunctuation $node): string
    {
        return $node->getGlyph() ?? SmartPunctuation::GLYPHS[$node->getKind()] ?? $node->getContent();
    }

    /**
     * @var int
     */
    private const MAX_RENDER_DEPTH = 512;

    protected string $listItemPrefix = '- ';

    protected string $orderedListItemPrefix = '. ';

    protected string $tableCellSeparator = ' | ';

    protected string $blockQuotePrefix = '"';

    protected string $blockQuoteSuffix = '"';

    protected SoftBreakMode $softBreakMode = SoftBreakMode::Space;

    protected HeadingIdTracker $headingIdTracker;

    protected int $renderDepth = 0;

    public function __construct()
    {
        $this->headingIdTracker = new HeadingIdTracker();
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
     */
    protected function renderAbbreviationDefinitions(Document $document): string
    {
        $lines = [];
        foreach ($document->getAbbreviationDefinitions() as $definition) {
            $lines[] = '*[' . $this->stripControls($definition['abbr']) . ']: '
                . $this->stripControls($definition['expansion']);
        }

        return $lines === [] ? '' : implode("\n\n", $lines) . "\n";
    }

    public function render(Document $document): string
    {
        $this->headingIdTracker->reset();
        (new CrossReferenceResolver())->resolve($document, $this->headingIdTracker);

        $text = $this->renderChildren($document);
        $abbreviations = $this->renderAbbreviationDefinitions($document);
        if ($abbreviations !== '') {
            $text = $document->hasAbbreviationsBeforeBody()
                ? $abbreviations . "\n" . $text
                : $text . "\n" . $abbreviations;
        }

        // Normalize multiple blank lines to single
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        // The two ends need different rules. At the START, trim blank lines only:
        // the indentation of the first content line is DATA -- a document opening
        // with a fenced code block whose first line is indented had that eaten
        // here, so a tab the HTML target emits inside `<code>` vanished from plain
        // text (carve#352, corpus 11-fenced-code-2). At the END, trailing
        // whitespace is still trimmed, because there it is layout rather than
        // content: a table row ending in an empty cell renders `x | ` and that
        // space is an artifact of the separator. rtrim's default character list
        // leaves a non-breaking space alone, which is what we want.
        $text = rtrim(ltrim($text, "\n")) . "\n";

        // The internal non-breaking-space placeholder (U+E000) collapses to an
        // ordinary space in plain text. Done after trimming so placeholder-derived
        // leading indentation (e.g. in a line block) survives. A literal U+00A0 in
        // the author's text is left intact.
        return str_replace("\u{E000}", ' ', $text);
    }

    protected function renderNode(Node $node): string
    {
        if ($this->renderDepth >= self::MAX_RENDER_DEPTH) {
            throw new RenderDepthExceededException(self::MAX_RENDER_DEPTH, 'plain-text');
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
                $node instanceof Div => $this->renderDiv($node),
                $node instanceof Paragraph => $this->renderParagraph($node),
                $node instanceof Heading => $this->renderHeading($node),
                $node instanceof CodeBlock => $this->renderCodeBlock($node),
                $node instanceof Figure => $this->renderFigure($node),
                $node instanceof Comment => '', // Skip comments
                $node instanceof RawBlock => '', // Skip raw blocks (format-specific)
                $node instanceof BlockQuote => $this->renderBlockQuote($node),
                $node instanceof ListBlock => $this->renderList($node),
                $node instanceof ListItem => $this->renderListItem($node),
                $node instanceof DefinitionList => $this->renderDefinitionList($node),
                $node instanceof DefinitionTerm => $this->renderDefinitionTerm($node),
                $node instanceof DefinitionDescription => $this->renderDefinitionDescription($node),
                $node instanceof ThematicBreak => $this->renderThematicBreak(),
                $node instanceof Table => $this->renderTable($node),
                $node instanceof TableRow => $this->renderTableRow($node),
                $node instanceof TableCell => $this->renderTableCell($node),
                $node instanceof LineBlock => $this->renderLineBlock($node),
                $node instanceof Footnote => $this->renderFootnote($node),
                $node instanceof Text => $this->stripControls($node->getContent()),
                $node instanceof EscapedText => $this->stripControls($node->getContent()),
                $node instanceof Code => $this->stripControls($node->getContent()),
                $node instanceof CriticComment => $this->stripControls($node->getContent()),
                $node instanceof Math => $this->stripControls($node->getContent()),
                $rawReference !== null => $this->stripControls($rawReference),
                $node instanceof Image => $this->stripControls($node->getAlt()),
                $node instanceof Mention => $this->renderMention($node),
                $node instanceof Link => $this->renderLink($node),
                $node instanceof Delete => '~' . $this->renderChildren($node) . '~',
                $node instanceof Substitution => '~' . $this->stripControls($node->getOldText()) . '~' . $this->stripControls($node->getNewText()),
                $node instanceof Symbol => ':' . $this->stripControls($node->getName()) . ':',
                $node instanceof InlineFootnote => '(' . $this->renderChildren($node) . ')',
                $node instanceof FootnoteRef && $node->isUnresolved()
                => $this->stripControls('[^' . $node->getLabel() . ']'),
                $node instanceof FootnoteRef => '[' . $this->stripControls($node->getLabel()) . ']',
                $node instanceof HeadingRef => $this->renderHeadingRef($node),
                $node instanceof CaptionNumber => $node->getNumber() === null ? '#' : (string)$node->getNumber(),
                $node instanceof SoftBreak => $this->softBreakMode === SoftBreakMode::Space ? ' ' : "\n",
                $node instanceof HardBreak => "\n",
                $node instanceof RawInline => '', // Skip raw inlines (format-specific)
                // §27: always emitted (unlike raw passthrough above), as plain prose.
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
        $label = $id === null ? null : $this->headingIdTracker->getTextForId($id);

        return $label === null ? '</#' . $this->stripControls($target) . '>' : $this->stripControls($label);
    }

    protected function renderChildren(Node $node): string
    {
        $text = '';
        foreach ($node->getChildren() as $child) {
            $text .= $this->renderNode($child);
        }

        return $text;
    }

    /**
     * Render inline nodes as plain text without resetting document-level state.
     *
     * @param list<\MarkupCarve\Carve\Node\Node> $nodes
     */
    public function renderInlineNodesFragment(array $nodes): string
    {
        $text = '';
        foreach ($nodes as $node) {
            $text .= $this->renderNode($node);
        }

        return str_replace("\u{E000}", ' ', $text);
    }

    protected function renderParagraph(Paragraph $node): string
    {
        return $this->renderChildren($node) . "\n\n";
    }

    protected function renderDiv(Div $node): string
    {
        $body = $this->renderChildren($node);
        $prefix = '';
        // Preserve an admonition's quoted opener header as a leading line.
        $title = $node->getHeader();
        if (is_string($title)) {
            $prefix .= $this->renderInlineNodesFragment($node->getHeaderNodes()) . "\n\n";
        }
        // PROPOSAL (graceful degradation): a grouping `[label]` (grammar PART 9
        // §12) is normally consumed by a group extension (e.g. tabs). When no
        // extension replaced this div, surface the label on its own leading
        // line so it is not silently dropped. Title (if any) renders first.
        $label = $node->getLabel();
        if ($label !== null && $label !== '') {
            $prefix .= $this->stripControls($label) . "\n\n";
        }

        return $prefix . $body;
    }

    protected function renderHeading(Heading $node): string
    {
        return $this->renderChildren($node) . "\n\n";
    }

    protected function renderCodeBlock(CodeBlock $node): string
    {
        return $this->stripControls($node->getContent()) . "\n\n";
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
                $output = rtrim($output, "\n") . $sep . trim($this->renderChildren($child)) . "\n\n";
            } else {
                $output .= $this->renderNode($child);
            }
        }

        return $output;
    }

    /**
     * Strip Unicode control characters (keeping tab and newline) from author
     * content so attacker text cannot inject terminal escape sequences into
     * plain-text output displayed in a terminal.
     */
    protected function stripControls(string $text): string
    {
        return (string)preg_replace('/(?!\x{0009}|\x{000A})\p{Cc}/u', '', $text);
    }

    protected function renderBlockQuote(BlockQuote $node): string
    {
        $content = trim($this->renderChildren($node));

        return $this->blockQuotePrefix . $content . $this->blockQuoteSuffix . "\n\n";
    }

    protected function renderList(ListBlock $node): string
    {
        $text = '';
        $counter = $node->getStart();

        foreach ($node->getChildren() as $child) {
            if ($child instanceof ListItem) {
                if ($node->getListType() === ListBlock::TYPE_ORDERED) {
                    $text .= $counter . $this->orderedListItemPrefix;
                    $counter++;
                } else {
                    $text .= $this->listItemPrefix;
                }
                $text .= trim($this->renderChildren($child)) . "\n";
            }
        }

        return $text . "\n";
    }

    protected function renderListItem(ListItem $node): string
    {
        return $this->renderChildren($node);
    }

    protected function renderDefinitionList(DefinitionList $node): string
    {
        return $this->renderChildren($node) . "\n";
    }

    protected function renderDefinitionTerm(DefinitionTerm $node): string
    {
        return $this->renderChildren($node) . "\n";
    }

    protected function renderDefinitionDescription(DefinitionDescription $node): string
    {
        return '  ' . trim($this->renderChildren($node)) . "\n";
    }

    protected function renderThematicBreak(): string
    {
        return "---\n\n";
    }

    protected function renderTable(Table $node): string
    {
        $text = '';
        $layout = TableLayout::expand(
            $node,
            fn (TableCell $cell): string => trim($this->renderChildren($cell)),
        );

        foreach ($layout['rows'] as $row) {
            $cells = [];
            $lastGenuine = -1;
            foreach ($row['cells'] as $i => $cell) {
                $cells[] = is_string($cell) ? $cell : '';
                if (is_string($cell)) {
                    $lastGenuine = $i;
                }
            }
            // Drop only SYNTHETIC trailing padding (non-string fillers a short/
            // rowspan row lacks), but KEEP a genuine trailing empty cell the row
            // authored (`| x || ` -> `x |`). Matches carve-rs.
            $cells = array_slice($cells, 0, $lastGenuine + 1);
            $text .= implode($this->tableCellSeparator, $cells) . "\n";
        }

        if ($node->hasCaption()) {
            /** @var \MarkupCarve\Carve\Node\Block\Caption $caption */
            $caption = $node->getCaption();
            $text .= $this->renderChildren($caption);
            $text = rtrim($text) . "\n";
        }

        return $text . "\n";
    }

    protected function renderTableRow(TableRow $node): string
    {
        $cells = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof TableCell) {
                $cells[] = trim($this->renderChildren($child));
            }
        }

        return implode($this->tableCellSeparator, $cells) . "\n";
    }

    protected function renderTableCell(TableCell $node): string
    {
        return $this->renderChildren($node);
    }

    protected function renderLineBlock(LineBlock $node): string
    {
        // Each child is a stanza, separated by a BLANK line. Joining them with a
        // single newline merged the stanzas into one run of lines, losing the
        // separation the source wrote and the HTML target keeps (carve#352, corpus
        // 41-line-blocks-3). Lines WITHIN a stanza are still single-newline
        // separated -- that is the line block's whole point.
        $stanzas = [];
        foreach ($node->getChildren() as $child) {
            $stanzas[] = trim($this->renderNode($child));
        }

        return implode("\n\n", $stanzas) . "\n\n";
    }

    protected function renderFootnote(Footnote $node): string
    {
        // The MARKER AS WRITTEN (PART 10 §10a): `[n]: …` is a LINK reference
        // definition, so emitting one where the author wrote a footnote
        // definition turns it into a different construct on the way back.
        return '[^' . $this->stripControls($node->getLabel()) . ']: '
            . trim($this->renderChildren($node)) . "\n";
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
        return $this->renderChildren($node);
    }

    /**
     * Set the prefix for unordered list items
     */
    public function setListItemPrefix(string $prefix): void
    {
        $this->listItemPrefix = $prefix;
    }

    /**
     * Set the suffix for ordered list item numbers
     */
    public function setOrderedListItemPrefix(string $prefix): void
    {
        $this->orderedListItemPrefix = $prefix;
    }

    /**
     * Set the separator between table cells
     */
    public function setTableCellSeparator(string $separator): void
    {
        $this->tableCellSeparator = $separator;
    }

    /**
     * Set the prefix for block quotes
     */
    public function setBlockQuotePrefix(string $prefix): void
    {
        $this->blockQuotePrefix = $prefix;
    }

    /**
     * Set the suffix for block quotes
     */
    public function setBlockQuoteSuffix(string $suffix): void
    {
        $this->blockQuoteSuffix = $suffix;
    }
}
