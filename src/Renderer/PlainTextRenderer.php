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
use MarkupCarve\Carve\Node\Inline\InlineNode;
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
use MarkupCarve\Carve\Renderer\Utility\AbbreviationBudgetTrait;
use MarkupCarve\Carve\Renderer\Utility\DerivedLabelTrait;
use MarkupCarve\Carve\Renderer\Utility\EventDispatcherTrait;
use MarkupCarve\Carve\Util\StringUtil;

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
    protected function renderAbbreviationDefinitions(Document $document): string
    {
        $lines = [];
        foreach ($document->getChildren() as $child) {
            if (!$child instanceof AbbreviationDefinition) {
                continue;
            }
            $lines[] = '*[' . $this->stripControls($child->getAbbr()) . ']: '
                . $this->stripControls($child->getExpansion());
        }

        return $lines === [] ? '' : implode("\n\n", $lines) . "\n";
    }

    public function render(Document $document): string
    {
        $this->headingIdTracker->reset();
        $this->resetExpansionBudgetForDocument($document);
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
        $text = rtrim(ltrim($text, "\n"), StringUtil::TRIMMABLE_WHITESPACE) . "\n";

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
                // A BLOCK-position image needs the separator a paragraph would
                // have added. Without it the image's alt text ran straight into
                // whatever followed - `alt textfollowing paragraph` - and it was
                // the only block in this renderer that contributed no boundary
                // at all (markup-carve/carve-rs#692). Decided by POSITION, not
                // by class: this match covers inline nodes too, and a bare
                // `instanceof Image` arm would split every inline image across
                // three lines. Same test the Markdown renderer already uses.
                $node instanceof Image && $this->isBlockPositionImage($node)
                => $this->stripControls($node->getAlt()) . "\n\n",
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
        $label = $id === null ? null : $this->headingIdTracker->getTextForId($id, $this->smartTypography);

        if ($id === null || $label === null) {
            return '</#' . $this->stripControls($target) . '>';
        }

        // Same expansion budget the other targets spend on this label, degrading
        // to the authored target (carve-php#1061). This target expands nothing
        // else - an abbreviation renders as its key here - which is why it had
        // no budget until the crossref needed one.
        //
        // THE LABEL IS THE HEADING'S INLINE NODES, rendered by THIS target
        // (PART 9R R4, markup-carve/carve#957). Plain text spells no markup, so
        // what changes here is not the delimiters but the CONTENT a node
        // carries: an inline literal and a symbol reach the label through the
        // same reader the heading itself used. A caption id has no heading
        // behind it and keeps the composed string.
        $nodes = $this->headingIdTracker->getLabelNodesForId($id);
        $rendered = $nodes === null
            ? $this->stripControls($label)
            : $this->renderDerivedLabel($nodes);

        return $this->chargeExpansion($rendered) ? $rendered : $this->stripControls($target);
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
                $output = rtrim($output, "\n") . $sep . trim($this->renderChildren($child), StringUtil::TRIMMABLE_WHITESPACE) . "\n\n";
            } else {
                $output .= $this->renderNode($child);
            }
        }

        return $output;
    }

    /**
     * Drop the control characters this target does NOT emit.
     *
     * PART 9 §29 C0 CONTROLS ON THE RENDER TARGETS: after
     * markup-carve/carve#963 the whitespace of the language is exactly U+0020,
     * U+0009, U+000A and U+000D, and every OTHER C0 control - U+0000..U+0008,
     * U+000B, U+000C, U+000E..U+001F - is ordinary CONTENT. PART 9 §29 T3 has the plain-text target EMIT the class, following the Markdown target: plain text is a text SERIALIZATION rather than a terminal format, so it takes the fidelity answer and not the device answer (§29 T3 records that half as a judgement rather than a measurement). A target that
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
     * The terminal target keeps its own broad strip; see
     * AnsiRenderer::stripControls(). Narrowing THAT one would be a security
     * regression, which is why the three targets spell this separately rather
     * than sharing one function.
     */

    /**
     * A lone image is a block-level image node, so it takes the block
     * separator. An image inside a paragraph or another inline is inline.
     */
    protected function isBlockPositionImage(Image $node): bool
    {
        $parent = $node->getParent();

        return $parent !== null && !$parent instanceof Paragraph && !$parent instanceof InlineNode;
    }

    protected function stripControls(string $text): string
    {
        return (string)preg_replace('/[\x{000D}\x{007F}-\x{009F}]/u', '', $text);
    }

    protected function renderBlockQuote(BlockQuote $node): string
    {
        $content = trim($this->renderChildren($node), StringUtil::TRIMMABLE_WHITESPACE);

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
                $text .= trim($this->renderChildren($child), StringUtil::TRIMMABLE_WHITESPACE) . "\n";
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
        return '  ' . trim($this->renderChildren($node), StringUtil::TRIMMABLE_WHITESPACE) . "\n";
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
            fn (TableCell $cell): string => trim($this->renderChildren($cell), StringUtil::TRIMMABLE_WHITESPACE),
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
            // Drop only SYNTHETIC trailing padding - columns this row does not
            // reach - but KEEP every column the row AUTHORED: a genuine trailing
            // empty cell (`| x || ` -> `x |`) and one a span CLAIMED alike. The
            // `<` and `^` markers are cells the writer typed, so a row whose
            // last column is covered by a span is not a short row, and cutting
            // it back to `$lastGenuine` truncated it. Matches carve-rs.
            $cells = array_slice($cells, 0, max($lastGenuine + 1, $row['authoredWidth']));
            $text .= implode($this->tableCellSeparator, $cells) . "\n";
        }

        if ($node->hasCaption()) {
            /** @var \MarkupCarve\Carve\Node\Block\Caption $caption */
            $caption = $node->getCaption();
            $text .= $this->renderChildren($caption);
            $text = rtrim($text, StringUtil::TRIMMABLE_WHITESPACE) . "\n";
        }

        return $text . "\n";
    }

    protected function renderTableRow(TableRow $node): string
    {
        $cells = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof TableCell) {
                $cells[] = trim($this->renderChildren($child), StringUtil::TRIMMABLE_WHITESPACE);
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
            $stanzas[] = trim($this->renderNode($child), StringUtil::TRIMMABLE_WHITESPACE);
        }

        return implode("\n\n", $stanzas) . "\n\n";
    }

    protected function renderFootnote(Footnote $node): string
    {
        // The MARKER AS WRITTEN (PART 10 §10a): `[n]: …` is a LINK reference
        // definition, so emitting one where the author wrote a footnote
        // definition turns it into a different construct on the way back.
        return '[^' . $this->stripControls($node->getLabel()) . ']: '
            . trim($this->renderChildren($node), StringUtil::TRIMMABLE_WHITESPACE) . "\n";
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
