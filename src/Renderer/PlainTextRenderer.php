<?php

declare(strict_types=1);

namespace Carve\Renderer;

use Carve\Event\RenderEvent;
use Carve\Node\Block\BlockQuote;
use Carve\Node\Block\CodeBlock;
use Carve\Node\Block\Comment;
use Carve\Node\Block\DefinitionDescription;
use Carve\Node\Block\DefinitionList;
use Carve\Node\Block\DefinitionTerm;
use Carve\Node\Block\Div;
use Carve\Node\Block\Footnote;
use Carve\Node\Block\Heading;
use Carve\Node\Block\LineBlock;
use Carve\Node\Block\ListBlock;
use Carve\Node\Block\ListItem;
use Carve\Node\Block\Paragraph;
use Carve\Node\Block\RawBlock;
use Carve\Node\Block\Table;
use Carve\Node\Block\TableCell;
use Carve\Node\Block\TableRow;
use Carve\Node\Block\ThematicBreak;
use Carve\Node\Document;
use Carve\Node\Inline\CaptionNumber;
use Carve\Node\Inline\Code;
use Carve\Node\Inline\Delete;
use Carve\Node\Inline\EscapedText;
use Carve\Node\Inline\FootnoteRef;
use Carve\Node\Inline\HardBreak;
use Carve\Node\Inline\HeadingRef;
use Carve\Node\Inline\Image;
use Carve\Node\Inline\InlineFootnote;
use Carve\Node\Inline\Link;
use Carve\Node\Inline\Math;
use Carve\Node\Inline\Mention;
use Carve\Node\Inline\RawInline;
use Carve\Node\Inline\RawText;
use Carve\Node\Inline\SoftBreak;
use Carve\Node\Inline\Substitution;
use Carve\Node\Inline\Symbol;
use Carve\Node\Inline\Text;
use Carve\Node\Node;
use Carve\Renderer\Utility\EventDispatcherTrait;

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

    public function render(Document $document): string
    {
        $this->headingIdTracker->reset();
        (new CrossReferenceResolver())->resolve($document, $this->headingIdTracker);

        $text = $this->renderChildren($document);

        // Normalize multiple blank lines to single
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        $text = trim($text) . "\n";

        // The internal non-breaking-space placeholder (U+E000) collapses to an
        // ordinary space in plain text. Done after trimming so placeholder-derived
        // leading indentation (e.g. in a line block) survives. A literal U+00A0 in
        // the author's text is left intact.
        return str_replace("\u{E000}", ' ', $text);
    }

    protected function renderNode(Node $node): string
    {
        if ($this->renderDepth >= self::MAX_RENDER_DEPTH) {
            return '';
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

            return match (true) {
                $node instanceof Document => $this->renderChildren($node),
                $node instanceof Div => $this->renderDiv($node),
                $node instanceof Paragraph => $this->renderParagraph($node),
                $node instanceof Heading => $this->renderHeading($node),
                $node instanceof CodeBlock => $this->renderCodeBlock($node),
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
                $node instanceof Math => $this->stripControls($node->getContent()),
                $node instanceof Image => $this->stripControls($node->getAlt()),
                $node instanceof Mention => $this->renderMention($node),
                $node instanceof Link => $this->renderLink($node),
                $node instanceof Delete => '~' . $this->renderChildren($node) . '~',
                $node instanceof Substitution => '~' . $this->stripControls($node->getOldText()) . '~' . $this->stripControls($node->getNewText()),
                $node instanceof Symbol => ':' . $this->stripControls($node->getName()) . ':',
                $node instanceof InlineFootnote => '(' . $this->renderChildren($node) . ')',
                $node instanceof FootnoteRef => '[' . $this->stripControls($node->getLabel()) . ']',
                $node instanceof HeadingRef => $this->renderHeadingRef($node),
                $node instanceof CaptionNumber => $node->getNumber() === null ? '#' : (string)$node->getNumber(),
                $node instanceof SoftBreak => $this->softBreakMode === SoftBreakMode::Space ? ' ' : "\n",
                $node instanceof HardBreak => "\n",
                $node instanceof RawInline => '', // Skip raw inlines (format-specific)
                $node instanceof RawText => $this->stripControls($node->getContent()),
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

    protected function renderParagraph(Paragraph $node): string
    {
        return $this->renderChildren($node) . "\n\n";
    }

    protected function renderDiv(Div $node): string
    {
        $body = $this->renderChildren($node);
        $prefix = '';
        // An admonition's quoted title is stored as the `title` attribute
        // (PART 9 §12); preserve it as a leading line instead of dropping.
        $title = $node->getAttribute('title');
        if (is_string($title) && $title !== '') {
            $prefix .= $this->stripControls($title) . "\n\n";
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
            foreach ($row['cells'] as $cell) {
                $cells[] = is_string($cell) ? $cell : '';
            }
            while ($cells !== [] && end($cells) === '') {
                array_pop($cells);
            }
            $text .= implode($this->tableCellSeparator, $cells) . "\n";
        }

        if ($node->hasCaption()) {
            /** @var \Carve\Node\Block\Caption $caption */
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
        $text = '';
        foreach ($node->getChildren() as $child) {
            $text .= trim($this->renderNode($child)) . "\n";
        }

        return $text . "\n";
    }

    protected function renderFootnote(Footnote $node): string
    {
        return '[' . $this->stripControls($node->getLabel()) . ']: ' . trim($this->renderChildren($node)) . "\n";
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
