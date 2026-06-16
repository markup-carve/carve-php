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
use Carve\Node\Inline\SoftBreak;
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

    protected string $listItemPrefix = '- ';

    protected string $orderedListItemPrefix = '. ';

    protected string $tableCellSeparator = ' | ';

    protected string $blockQuotePrefix = '"';

    protected string $blockQuoteSuffix = '"';

    protected HeadingIdTracker $headingIdTracker;

    public function __construct()
    {
        $this->headingIdTracker = new HeadingIdTracker();
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
        // Dispatch render events
        $eventName = 'render.' . $node->getType();
        $event = new RenderEvent($node);
        $this->dispatchEvent($eventName, $event);
        $this->dispatchEvent('render.*', $event);

        if ($event->isDefaultPrevented()) {
            return $event->getHtml() ?? '';
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
            $node instanceof Text => $node->getContent(),
            $node instanceof EscapedText => $node->getContent(),
            $node instanceof Code => $node->getContent(),
            $node instanceof Math => $node->getContent(),
            $node instanceof Image => $node->getAlt(),
            $node instanceof Mention => $this->renderMention($node),
            $node instanceof Link => $this->renderLink($node),
            $node instanceof Delete => '~' . $this->renderChildren($node) . '~',
            $node instanceof Symbol => ':' . $node->getName() . ':',
            $node instanceof InlineFootnote => '(' . $this->renderChildren($node) . ')',
            $node instanceof FootnoteRef => '[' . $node->getLabel() . ']',
            $node instanceof HeadingRef => $this->renderHeadingRef($node),
            $node instanceof CaptionNumber => $node->getNumber() === null ? '#' : (string)$node->getNumber(),
            // A soft break is a single source newline that stays inside the
            // paragraph; in plain text it renders as a space. For a visible line
            // break use a `::: |` line block or a trailing backslash hard break.
            $node instanceof SoftBreak => ' ',
            $node instanceof HardBreak => "\n",
            $node instanceof RawInline => '', // Skip raw inlines (format-specific)
            default => $this->renderChildren($node),
        };
    }

    protected function renderHeadingRef(HeadingRef $node): string
    {
        $target = $node->getTargetId();
        // Exact match first, then a case-insensitive fallback (matches HtmlRenderer).
        $id = $this->headingIdTracker->findIdCaseInsensitive($target);
        $label = $id === null ? null : $this->headingIdTracker->getTextForId($id);

        return $label ?? '</#' . $target . '>';
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
        // An admonition's quoted title is stored as the `title` attribute
        // (PART 9 §12); preserve it as a leading line instead of dropping.
        $title = $node->getAttribute('title');
        if (is_string($title) && $title !== '') {
            return $title . "\n\n" . $body;
        }

        return $body;
    }

    protected function renderHeading(Heading $node): string
    {
        return $this->renderChildren($node) . "\n\n";
    }

    protected function renderCodeBlock(CodeBlock $node): string
    {
        return $node->getContent() . "\n\n";
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

        foreach ($node->getChildren() as $child) {
            if ($child instanceof TableRow) {
                $text .= $this->renderTableRow($child);
            }
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
        return '[' . $node->getLabel() . ']: ' . trim($this->renderChildren($node)) . "\n";
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
        return $node->getDestination() ?? $this->renderChildren($node);
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
