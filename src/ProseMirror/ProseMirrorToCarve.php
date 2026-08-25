<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\ProseMirror;

use Closure;
use MarkupCarve\Carve\Ast\PayloadDepth;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\Frontmatter;
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
use MarkupCarve\Carve\Node\Block\LinkReferenceDefinition;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Node\Block\Section;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Block\ThematicBreak;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Abbreviation;
use MarkupCarve\Carve\Node\Inline\CitationGroup;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\CriticComment;
use MarkupCarve\Carve\Node\Inline\Delete;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
use MarkupCarve\Carve\Node\Inline\Highlight;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\InlineExtension;
use MarkupCarve\Carve\Node\Inline\InlineFootnote;
use MarkupCarve\Carve\Node\Inline\InlineNode;
use MarkupCarve\Carve\Node\Inline\Insert;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\LiteralInline;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Inline\RawInline;
use MarkupCarve\Carve\Node\Inline\RawText;
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
use MarkupCarve\Carve\Parser\HeadingReferenceCollector;
use MarkupCarve\Carve\Parser\LabelKey;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Builds a Carve AST from a ProseMirror (Tiptap) document.
 *
 * The counterpart of ProseMirrorRenderer, and of `serializeToCarve` in
 * carve-grammars - except this reads the tree rather than emitting source, so an
 * application can go editor to AST to any renderer without a Node runtime.
 *
 * The inverse of the two shape differences applies: a text node's marks become
 * nested Carve inline elements (innermost mark closest to the text), and a
 * ProseMirror name is resolved back through the published schema map.
 *
 * Names the map does not know are an error rather than a silent skip. An editor
 * that grew a node type nobody mapped is exactly the case where dropping content
 * quietly is worst.
 */
class ProseMirrorToCarve
{
    /**
     * @var array<string, string>
     */
    private array $incomingAbbreviations = [];

    /**
     * How deeply an incoming ProseMirror payload may nest.
     *
     * `json_decode`'s own default, named rather than repeated: the string entry
     * point and the array entry point beside it have to bound the same set of
     * payloads, and a literal in one of them is how the two drift apart.
     *
     * @var int
     */
    public const MAX_JSON_DEPTH = 512;

    /**
     * Carve types that hold their content as a string and take a ProseMirror
     * MARK rather than a node, so the text arrives on a text node and has to be
     * lifted back onto the Carve node.
     *
     * @var array<string, class-string<\MarkupCarve\Carve\Node\Inline\InlineNode>>
     */
    private const CONTENT_BEARING_MARKS = [
        'code' => Code::class,
        'critic_comment' => CriticComment::class,
    ];

    /**
     * ProseMirror names that are the INLINE spelling of a Carve type whose node
     * class is filed under blocks.
     *
     * Only `comment` has two spellings, and the map lists them in one entry
     * (`carveComment`, `carveCommentInline`) with no field saying which is
     * which - so this is the position that entry does not record, not a second
     * copy of the mapping. The renderer picks between the same two names.
     *
     * @var array<string>
     */
    private const INLINE_ONLY_NAMES = ['carveCommentInline'];

    /**
     * @var array<string, string> attribute name => why it could not be carried
     */
    protected array $droppedAttributes = [];

    /**
     * @var array<string, \Closure(array<string, mixed>): \MarkupCarve\Carve\Node\Node>
     */
    protected array $factories = [];

    /**
     * @param array<string, mixed> $document
     *
     * @throws \RuntimeException When the payload is not a ProseMirror document.
     */
    public function convert(array $document): Document
    {
        // `convertJson()` is bounded by the depth argument it hands
        // `json_decode`; this entry point takes a structure somebody else
        // decoded, and `buildBlock` recurses through it, so the same bound has
        // to be applied by hand or a deep enough payload exhausts the C stack.
        // Asked before the root-type check, because a payload too deep to walk
        // is refused for that and not for what it claims to be.
        if (!PayloadDepth::within($document, self::MAX_JSON_DEPTH)) {
            throw new RuntimeException(sprintf(
                'The ProseMirror payload nests deeper than %d levels, the bound `convertJson()` applies.',
                self::MAX_JSON_DEPTH,
            ));
        }

        $type = $document['type'] ?? null;
        if ($type !== 'doc') {
            throw new RuntimeException('The payload root must be a ProseMirror doc node');
        }

        $this->droppedAttributes = [];

        $rootAttrs = is_array($document['attrs'] ?? null) ? $document['attrs'] : [];
        $incomingAbbreviations = $rootAttrs['carveAbbreviations'] ?? [];
        $this->incomingAbbreviations = is_array($incomingAbbreviations) ? array_filter(
            $incomingAbbreviations,
            'is_string',
        ) : [];

        $carveDocument = new Document();
        foreach ($this->buildBlockPositionChildren($this->childrenOf($document)) as $node) {
            $carveDocument->appendChild($node);
        }

        // After the tree is final, not while it is being built. A link mark
        // spans one text run per mark set, so `[*bold* heading][]` arrives as
        // two link wrappers that mergeAdjacentMarks() joins later; asked any
        // earlier, the check would see the label's first fragment and reject
        // every reference whose label carries markup at all.
        //
        // The index is the RESOLVER's own, built from the tree that is about to
        // be written, so the question asked is the one resolution will ask
        // rather than a re-derivation of it.
        $this->confirmHeadingReferences(
            $carveDocument,
            (new HeadingReferenceCollector(new HeadingIdTracker()))->collect($carveDocument),
        );

        // The same re-derivation for `[text][label]` references, against the
        // `carveLinkRefDef` nodes the payload carried. A reference whose
        // definition is gone - or points somewhere else now - is not a link
        // any more once written, so it falls back to its inline form, which
        // always renders correctly. Labels use the parser's shared normalized
        // key while their authored spelling stays on the node.
        $this->confirmLabelReferences($carveDocument, $this->collectLinkReferenceDefinitions($carveDocument));

        // Restore document-level abbreviation definitions (carve-php#519). See
        // the note in ProseMirrorRenderer::render(): without these the marks
        // come back but the definitions do not, so the written source loses
        // every expansion.
        $attrs = $document['attrs'] ?? [];
        $abbreviations = is_array($attrs) ? $attrs['carveAbbreviations'] ?? null : null;
        if (is_array($attrs) && is_array($abbreviations) && $abbreviations !== []) {
            // Narrowed rather than asserted: the payload is decoded JSON, so
            // the values are whatever the caller sent. A non-string expansion
            // is not a definition, so it is skipped rather than coerced into
            // one - coercing would invent an expansion nobody wrote.
            $definitions = [];
            foreach ($abbreviations as $abbr => $expansion) {
                if (!is_string($expansion)) {
                    continue;
                }
                $definitions[(string)$abbr] = $expansion;
            }
            $carveDocument->setAbbreviations($definitions);
            // The authored list, when the payload carries one. Narrowed the
            // same way as the map: an entry without two string fields is not a
            // definition. Absent, the map is all there is, and a term defined
            // twice comes back as one line - which is what this attr exists to
            // prevent (carve#553).
            $authored = $attrs['carveAbbreviationDefinitions'] ?? null;
            if (is_array($authored)) {
                $ordered = [];
                foreach ($authored as $definition) {
                    if (
                        !is_array($definition)
                        || !is_string($definition['abbr'] ?? null)
                        || !is_string($definition['expansion'] ?? null)
                    ) {
                        continue;
                    }
                    $ordered[] = ['abbr' => $definition['abbr'], 'expansion' => $definition['expansion']];
                }
                if ($ordered !== []) {
                    $carveDocument->setAbbreviationDefinitions($ordered);
                }
            }
            $carveDocument->setAbbreviationsBeforeBody(
                (bool)($attrs['carveAbbreviationsBeforeBody'] ?? false),
            );
            // REBUILD THE NODES, not just the two side tables. Every non-HTML
            // renderer writes a definition from its `AbbreviationDefinition`
            // child, at that child's position, so a document carrying only the
            // tables comes back with no definition lines at all. ProseMirror
            // records one flag rather than per-definition positions, so the
            // authored order is restored at the end the flag names - the same
            // position this payload was produced from.
            $restored = [];
            foreach ($carveDocument->getAbbreviationDefinitions() as $definition) {
                $restored[] = new AbbreviationDefinition($definition['abbr'], $definition['expansion']);
            }
            if ($restored !== []) {
                $children = $carveDocument->getChildren();
                $carveDocument->setChildren(
                    $carveDocument->hasAbbreviationsBeforeBody()
                        ? array_merge($restored, $children)
                        : array_merge($children, $restored),
                );
            }
        }

        return $carveDocument;
    }

    /**
     * Teach this converter one ProseMirror name its editor emits.
     *
     * The published map is CarveKit's vocabulary, so a name outside it is
     * rejected - which is right for a typo and wrong for an application's own
     * node, whose name cannot go upstream because nobody else has it. Every
     * other surface in this package already takes a downstream extension; this
     * is the same door for the bridge.
     *
     * The factory returns the node SHELL, exactly where `instantiate()` sits:
     * attributes and children are then applied by the normal path, so an app
     * gets `data-*` passthrough and nested content without reimplementing them.
     * A node answering `InlineNode` is treated as inline, so both kinds work.
     *
     * ~~~ php
     * $converter->register('placeholderToken', function (array $data): Node {
     *     $span = new Span();
     *     $span->addClass('placeholder');
     *
     *     return $span;
     * });
     * ~~~
     *
     * Anything unregistered still throws, so nothing becomes silent.
     *
     * @param string $proseMirrorName
     * @param \Closure(array<string, mixed>): \MarkupCarve\Carve\Node\Node $factory
     *
     * @return $this
     */
    public function register(string $proseMirrorName, Closure $factory)
    {
        $this->factories[$proseMirrorName] = $factory;

        return $this;
    }

    /**
     * Attributes the last conversion could not carry, as name => reason.
     *
     * Empty means every attribute in the payload reached the tree. The mirror
     * of `ProseMirrorRenderer::droppedTypes()` for the other direction: an
     * application storing documents should assert on this rather than trust it.
     *
     * @return array<string, string>
     */
    public function droppedAttributes(): array
    {
        return $this->droppedAttributes;
    }

    public function convertJson(string $json): Document
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);

        return $this->convert($data);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException When the ProseMirror name is not in the map.
     */
    protected function buildBlock(array $data): ?Node
    {
        $name = $data['type'] ?? null;
        if (!is_string($name)) {
            throw new RuntimeException('Every ProseMirror node needs a string type');
        }

        // A figure's SHORT caption is state, not a child, so the generic child
        // loop cannot restore it: the flag lives on the payload child's attrs
        // and would be gone by the time the built Caption node arrives. The
        // main caption stays an ordinary child, which is where the writer
        // reads it.
        if ($name === 'carveFigure') {
            $figure = new Figure();
            $this->applyAttributes($figure, $data);
            foreach ($this->childrenOf($data) as $child) {
                $childAttrs = is_array($child['attrs'] ?? null) ? $child['attrs'] : [];
                if (($child['type'] ?? null) === 'carveCaption' && self::asBool($childAttrs['short'] ?? false)) {
                    $shortCaption = [];
                    foreach ($this->childrenOf($child) as $inlineChild) {
                        foreach ($this->buildInlines($inlineChild) as $built) {
                            if ($built instanceof InlineNode) {
                                $shortCaption[] = $built;
                            }
                        }
                    }
                    $figure->setShortCaption($shortCaption);

                    continue;
                }
                // A figure's IMAGE panel crosses wrapped in a paragraph, which
                // is what the ProseMirror schema takes in a figure. Unwrap that
                // one shape and nothing else: a paragraph holding anything else
                // - display math, prose - is a block the figure captions, and
                // flattening it to inlines dropped the whole panel.
                $paragraphImage = ($child['type'] ?? null) === 'paragraph'
                    ? $this->loneParagraphImage($child)
                    : null;
                if ($paragraphImage !== null) {
                    foreach ($this->buildInlines($paragraphImage) as $built) {
                        $figure->appendChild($built);
                    }

                    continue;
                }
                $built = $this->buildBlock($child);
                if ($built !== null) {
                    $figure->appendChild($built);
                }
            }

            return $figure;
        }

        // The renderer emits a table caption as this leading child; put it back
        // on the table as state.
        if ($name === 'carveCaption') {
            $caption = new Caption();
            foreach ($this->childrenOf($data) as $child) {
                foreach ($this->buildInlines($child) as $built) {
                    $caption->appendChild($built);
                }
            }

            return $caption;
        }

        // The renderer hoists Carve sections into this wrapper; unwrap it.
        if ($name === 'carveSection') {
            $section = new Section();
            foreach ($this->buildBlockPositionChildren($this->childrenOf($data)) as $built) {
                $section->appendChild($built);
            }

            return $section;
        }

        $node = $this->instantiate($name, $data);
        $this->applyAttributes($node, $data);

        if ($node instanceof CodeBlock || $node instanceof RawBlock || $node instanceof Comment) {
            // Same asymmetry as inline code: the text is state, not children.
            // A `carveCommentInline` payload carries its text in a `content`
            // attr instead and has no children, so the attributes pass has
            // already set it; joining an empty child list here must not erase
            // that.
            $text = '';
            foreach ($this->childrenOf($data) as $child) {
                $text .= self::asString($child['text'] ?? '');
            }
            if (!($node instanceof Comment) || $text !== '' || $this->childrenOf($data) !== []) {
                $this->setState($node, 'content', $text);
            }

            return $node;
        }

        if ($node instanceof TableCell) {
            foreach ($this->buildTableCellInlines($data) as $built) {
                $node->appendChild($built);
            }

            return $node;
        }

        $inline = in_array($node->getType(), ['paragraph', 'heading', 'definition_term', 'caption'], true);
        if ($inline) {
            $inlines = [];
            foreach ($this->childrenOf($data) as $child) {
                foreach ($this->buildInlines($child) as $built) {
                    $inlines[] = $built;
                }
            }
            foreach ($this->mergeAdjacentMarks($inlines) as $built) {
                $node->appendChild($built);
            }

            return $node;
        }

        foreach ($this->buildBlockPositionChildren($this->childrenOf($data)) as $built) {
            if ($built instanceof Caption && ($node instanceof Table || $node instanceof FigureGroup)) {
                // A caption is STATE on both, not a child: a table's is written
                // above it and a composite figure's below the closing fence,
                // and neither renderer walks it as content. Left in `children`
                // the group would gain a stray caption block and lose the one
                // authored channel it has (PART 9 section 4c).
                $node->setCaption($built);

                continue;
            }
            $node->appendChild($built);
        }

        if ($node instanceof TableRow) {
            // ProseMirror marks header CELLS; Carve also carries the flag on the
            // row, which is what puts the row in <thead>.
            $cells = $node->getChildren();
            $allHeaders = $cells !== [];
            foreach ($cells as $cell) {
                if (!$cell instanceof TableCell || !$cell->isHeader()) {
                    $allHeaders = false;

                    break;
                }
            }
            if ($allHeaders) {
                $this->setState($node, 'isHeader', true);
            }
        }

        if ($node instanceof Table) {
            // ProseMirror's table is a MERGED-cell model: a spanning cell
            // carries its own `colspan`/`rowspan` and the columns it covers
            // simply have no cell there. Carve's is a placeholder model
            // (carve-php#527, carve-js parity): every column keeps a cell,
            // `^`/`<` markers included, so every row has the same width. This
            // rebuilds that shape from the merged one, the same way
            // HtmlToCarve turns an HTML `colspan`/`rowspan` into `<`/`^`
            // continuation markers - just at the node level instead of
            // through Carve source text.
            $this->normalizeTableSpans($node);
        }

        return $node;
    }

    /**
     * Splice `^`/`<` placeholder cells into a ProseMirror-imported table's
     * rows so every row ends up with one cell per grid column, matching this
     * engine's own parser output (carve-php#527). Mirrors
     * `HtmlToCarve::processTable`'s rowspan-map walk, but builds `TableCell`
     * nodes directly instead of Carve source text.
     */
    protected function normalizeTableSpans(Table $table): void
    {
        /** @var array<int, int> $rowspanMap */
        $rowspanMap = [];

        foreach ($table->getChildren() as $row) {
            if (!$row instanceof TableRow) {
                continue;
            }

            $originalCells = [];
            foreach ($row->getChildren() as $child) {
                if ($child instanceof TableCell) {
                    $originalCells[] = $child;
                }
            }

            $isHeaderRow = $row->isHeader();
            $newCells = [];
            $logicalCol = 0;

            foreach ($originalCells as $cell) {
                $this->drainRowspans($newCells, $rowspanMap, $logicalCol, $isHeaderRow);

                $colspan = max(1, $cell->getColspan());
                $rowspan = max(1, $cell->getRowspan());
                $newCells[] = $cell;
                if ($rowspan > 1) {
                    $rowspanMap[$logicalCol] = ($rowspanMap[$logicalCol] ?? 0) + ($rowspan - 1);
                }
                $logicalCol++;

                for ($cs = 1; $cs < $colspan; $cs++) {
                    // A column a rowspan from an earlier row already holds is
                    // not this cell's to continue into: the `^` comes first and
                    // the `<` lands past it. Draining only before a cell (and
                    // not here) is what flattened `| p | ^ | < | e |` back to
                    // four plain cells - the colspan continuation took the
                    // column the rowspan owned, so no `^` was ever emitted.
                    $this->drainRowspans($newCells, $rowspanMap, $logicalCol, $isHeaderRow);
                    $newCells[] = $this->spanPlaceholder($isHeaderRow, '<');
                    $logicalCol++;
                }
            }

            // Trailing rowspan continuations for a row where the last real
            // cell does not reach the table's full width.
            $this->drainRowspans($newCells, $rowspanMap, $logicalCol, $isHeaderRow);

            if (count($newCells) === count($originalCells)) {
                // No span in this row; nothing to splice.
                continue;
            }

            foreach ($row->getChildren() as $existing) {
                $row->removeChild($existing);
            }
            foreach ($newCells as $newCell) {
                $row->appendChild($newCell);
            }
        }
    }

    /**
     * Emit a `^` placeholder for every rowspan an earlier row left pending at
     * this column, advancing past each one.
     *
     * @param array<\MarkupCarve\Carve\Node\Block\TableCell> $newCells
     * @param array<int, int> $rowspanMap
     * @param bool $isHeaderRow
     * @param int $logicalCol
     */
    protected function drainRowspans(array &$newCells, array &$rowspanMap, int &$logicalCol, bool $isHeaderRow): void
    {
        while (($rowspanMap[$logicalCol] ?? 0) > 0) {
            $newCells[] = $this->spanPlaceholder($isHeaderRow, '^');
            $rowspanMap[$logicalCol]--;
            if ($rowspanMap[$logicalCol] === 0) {
                unset($rowspanMap[$logicalCol]);
            }
            $logicalCol++;
        }
    }

    protected function spanPlaceholder(bool $isHeader, string $marker): TableCell
    {
        $cell = new TableCell($isHeader);
        $cell->setSpanMarker($marker);

        return $cell;
    }

    /**
     * @param array<int, array<string, mixed>> $children
     *
     * @return array<\MarkupCarve\Carve\Node\Node>
     */
    protected function buildBlockPositionChildren(array $children): array
    {
        $builtChildren = [];
        $inlineRun = [];

        foreach ($children as $child) {
            if (($child['type'] ?? null) === 'carveUnsupported') {
                $attrs = is_array($child['attrs'] ?? null) ? $child['attrs'] : [];
                $source = self::asString($attrs['carveSource'] ?? '');
                $preserved = new Paragraph();
                $preserved->appendChild(new RawText($source));
                $builtNodes = [$preserved];
            } elseif ($this->isInlinePayload($child)) {
                $builtNodes = $this->buildInlines($child);
            } else {
                $block = $this->buildBlock($child);
                $builtNodes = $block === null ? [] : [$block];
            }

            foreach ($builtNodes as $built) {
                if ($built instanceof InlineNode) {
                    $inlineRun[] = $built;

                    continue;
                }

                $this->flushBlockPositionInlines($builtChildren, $inlineRun);
                $builtChildren[] = $built;
            }
        }
        $this->flushBlockPositionInlines($builtChildren, $inlineRun);

        return $builtChildren;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $builtChildren
     * @param array<\MarkupCarve\Carve\Node\Inline\InlineNode> $inlineRun
     */
    protected function flushBlockPositionInlines(array &$builtChildren, array &$inlineRun): void
    {
        if ($inlineRun === []) {
            return;
        }

        $paragraph = new Paragraph();
        foreach ($this->mergeAdjacentMarks($inlineRun) as $inline) {
            $paragraph->appendChild($inline);
        }
        $builtChildren[] = $paragraph;
        $inlineRun = [];
    }

    /**
     * Build a table cell's direct inline children.
     *
     * ProseMirror table cells require block content, usually a single
     * paragraph. Carve table cell source is inline-only, so block children have
     * their inlines lifted directly into the cell.
     *
     * A Carve table row is one line, so nothing that would end that line has a
     * form inside a cell. Two deliberate degradations follow, both to a single
     * space, at every depth of the lifted subtree:
     *
     * - a block boundary, so a cell holding two paragraphs or a list keeps its
     *   word boundaries instead of running the text together;
     * - a hard break, which would otherwise be written as a backslash line
     *   break and terminate the table at that point, turning the whole row back
     *   into a paragraph on reparse.
     *
     * Both keep the cell content instead of losing it entirely.
     *
     * @param array<string, mixed> $data
     *
     * @return array<\MarkupCarve\Carve\Node\Node>
     */
    protected function buildTableCellInlines(array $data): array
    {
        return $this->mergeAdjacentMarks($this->liftBlockInlines($data));
    }

    /**
     * Lift a cell subtree's inline content, joining sibling blocks with a space.
     *
     * @param array<string, mixed> $data
     *
     * @return array<\MarkupCarve\Carve\Node\Node>
     */
    protected function liftBlockInlines(array $data): array
    {
        $inlines = [];
        foreach ($this->childrenOf($data) as $child) {
            if ($this->isProseMirrorBlock($child)) {
                $lifted = $this->liftBlockInlines($child);
                if ($lifted !== [] && $inlines !== []) {
                    $inlines[] = new Text(' ');
                }
                foreach ($lifted as $built) {
                    $inlines[] = $built;
                }

                continue;
            }

            foreach ($this->buildInlines($child) as $built) {
                $inlines[] = $this->replaceHardBreaks($built);
            }
        }

        return $inlines;
    }

    /**
     * Swap every hard break in a lifted inline tree for a space.
     *
     * A break can be nested inside a mark (bold text carrying a shift-enter), so
     * the whole subtree is walked rather than only its top level.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     *
     * @return \MarkupCarve\Carve\Node\Node
     */
    protected function replaceHardBreaks(Node $node): Node
    {
        if ($node instanceof HardBreak) {
            return new Text(' ');
        }

        $children = $node->getChildren();
        if ($children === []) {
            return $node;
        }

        $replaced = [];
        foreach ($children as $child) {
            $replaced[] = $this->replaceHardBreaks($child);
        }
        $this->replaceChildren($node, $replaced);

        return $node;
    }

    /**
     * Whether a payload maps to an inline node, decided from the schema map
     * alone.
     *
     * Deliberately cheaper and more forgiving than {@see self::isProseMirrorBlock()}:
     * it builds nothing, and an unrecognized type answers false so the caller
     * hands it to `buildBlock()`, which owns the diagnostic for a name that is
     * not in the map. The strict variant exists for the cell path, where the
     * node has to be built anyway.
     *
     * @param array<string, mixed> $data
     */
    protected function isInlinePayload(array $data): bool
    {
        $name = $data['type'] ?? null;
        if (!is_string($name)) {
            return false;
        }

        $carveType = SchemaMap::carveTypeFor($name);
        $class = $carveType !== null ? (self::CLASS_MAP[$carveType] ?? null) : null;

        return is_string($class) && is_a($class, InlineNode::class, true);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException
     */
    protected function isProseMirrorBlock(array $data): bool
    {
        $name = $data['type'] ?? null;
        if (!is_string($name)) {
            throw new RuntimeException('Every ProseMirror node needs a string type');
        }

        // A comment is one Carve type with two spellings, and BOTH are modelled
        // by `Node\Block\Comment` - so the class test below, which is the whole
        // of this predicate, answers "block" for the inline atom as well. In a
        // table cell that sent the atom down the lifting path, which recurses
        // into children an atom does not have, and the comment was gone with
        // nothing reported. The renderer already chose the name from the
        // position it found the node in; this reads that choice back.
        if (in_array($name, self::INLINE_ONLY_NAMES, true)) {
            return false;
        }

        $node = $this->instantiate($name, $data);

        return !($node instanceof InlineNode);
    }

    /**
     * Merge runs of the same mark element back into one.
     *
     * ProseMirror stores marks per text node, so `*bold with /italic/ inside*`
     * arrives as three separately-bolded pieces. Reassembling them literally
     * yields three adjacent <strong> elements where Carve had one, which is a
     * visible HTML difference even though the text is intact.
     *
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     *
     * @return array<\MarkupCarve\Carve\Node\Node>
     */
    protected function mergeAdjacentMarks(array $nodes): array
    {
        $merged = [];
        foreach ($nodes as $node) {
            $previous = $merged[count($merged) - 1] ?? null;

            // A content-bearing mark never merges. Its text lives on the node
            // rather than in children, so appending children would silently drop
            // the second one's content - and merging would be wrong even if it
            // were lossless, since `{#a#}{#b#}` is two comments, not one.
            $mergeable = SchemaMap::isMark($node->getType())
                && !isset(self::CONTENT_BEARING_MARKS[$node->getType()]);

            if (
                $previous !== null
                && $previous::class === $node::class
                && $mergeable
                && $previous->getAttributes() === $node->getAttributes()
            ) {
                foreach ($node->getChildren() as $child) {
                    $previous->appendChild($child);
                }
                // Recurse so nested runs inside the merged element coalesce too.
                $this->replaceChildren($previous, $this->mergeAdjacentMarks($previous->getChildren()));

                continue;
            }

            if ($mergeable) {
                $this->replaceChildren($node, $this->mergeAdjacentMarks($node->getChildren()));
            }

            $merged[] = $node;
        }

        return $merged;
    }

    /**
     * Swap a node's children for a rebuilt list. Node exposes append and remove
     * rather than a bulk setter, so the old ones are removed first.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<\MarkupCarve\Carve\Node\Node> $children
     */
    protected function replaceChildren(Node $node, array $children): void
    {
        foreach ($node->getChildren() as $existing) {
            $node->removeChild($existing);
        }
        foreach ($children as $child) {
            $node->appendChild($child);
        }
    }

    /**
     * A ProseMirror inline node becomes one or more Carve nodes: a text node with
     * marks nests into mark elements, outermost mark first.
     *
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException
     *
     * @return array<\MarkupCarve\Carve\Node\Node>
     */
    protected function buildInlines(array $data): array
    {
        $name = $data['type'] ?? null;
        if (!is_string($name)) {
            throw new RuntimeException('Every ProseMirror node needs a string type');
        }

        if ($name === 'carveUnsupportedInline') {
            $attrs = is_array($data['attrs'] ?? null) ? $data['attrs'] : [];

            return [new RawText(self::asString($attrs['carveSource'] ?? ''))];
        }

        if ($name === 'carveEmptyMark') {
            $attrs = is_array($data['attrs'] ?? null) ? $data['attrs'] : [];
            $node = $this->emptyMarkNode(
                self::asString($attrs['markType'] ?? ''),
                is_array($attrs['markAttrs'] ?? null) ? $attrs['markAttrs'] : [],
            );

            return [$this->wrapInMarks($node, $data['marks'] ?? [])];
        }

        if ($name === 'text') {
            $text = self::asString($data['text'] ?? '');
            $marks = is_array($data['marks'] ?? null) ? $data['marks'] : [];

            // `code` and `critic_comment` are marks in ProseMirror but
            // content-bearing nodes in Carve: the text belongs on the node, and
            // appending it as a child would render an empty element.
            foreach (self::CONTENT_BEARING_MARKS as $carveType => $class) {
                $markIndex = null;
                foreach ($marks as $index => $mark) {
                    if (is_array($mark) && ($mark['type'] ?? null) === SchemaMap::nameFor($carveType)) {
                        $markIndex = $index;

                        break;
                    }
                }

                if ($markIndex !== null) {
                    $mark = $marks[$markIndex];
                    unset($marks[$markIndex]);

                    // The mark carries this node's own attributes, so they have
                    // to come off the MARK rather than the text node wrapping
                    // it - `` `code`{.cls} `` lost its class here (carve-php#519).
                    $node = new $class($text);
                    if (is_array($mark)) {
                        // Rebuilt rather than passed through: the payload is
                        // decoded JSON, so its keys are whatever the caller
                        // sent, and `attrs` is the only part that applies here.
                        $this->applyAttributes($node, ['attrs' => $mark['attrs'] ?? []]);
                    }

                    return [$this->wrapInMarks($node, array_values($marks))];
                }
            }

            if (str_contains($text, "\n")) {
                $nodes = [];
                foreach (preg_split('/(\n)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
                    if ($part === "\n") {
                        $nodes[] = $this->wrapInMarks(new SoftBreak(), $marks);
                    } elseif ($part !== '') {
                        $nodes[] = $this->wrapInMarks(new Text($part), $marks);
                    }
                }

                return $nodes;
            }

            return [$this->wrapInMarks(new Text($text), $marks)];
        }

        $node = $this->instantiate($name, $data);
        $this->applyAttributes($node, $data);
        foreach ($this->childrenOf($data) as $child) {
            foreach ($this->buildInlines($child) as $built) {
                $node->appendChild($built);
            }
        }

        return [$this->wrapInMarks($node, $data['marks'] ?? [])];
    }

    /**
     * Keep the autolink flag only while the link still IS one.
     *
     * The writer spells an autolink from its TEXT, not its destination, so a
     * flag restored onto a link whose two have diverged does not merely change
     * the spelling - it publishes the text as the destination and the real one
     * is gone. An editor that retyped the visible text of `<https://example.com>`
     * would come back as `<changed>`, which is not even a link.
     *
     * That is the shape of carve-php#516, arriving by a different door, so the
     * flag is treated as a HINT to be re-derived rather than as truth: an
     * autolink is exactly a link whose text round-trips to its own destination,
     * and anything else stays an explicit link, which always renders correctly.
     */
    protected function confirmAutolink(Link $link): void
    {
        if (!$link->isAutolink()) {
            return;
        }

        $children = $link->getChildren();
        $text = count($children) === 1 && $children[0] instanceof Text ? $children[0]->getContent() : null;

        // `mailto:` is the one destination the parser adds that the author did
        // not write, so an email autolink is still one.
        if ($text === null || ($text !== $link->getDestination() && $link->getDestination() !== 'mailto:' . $text)) {
            $link->setAutolink(false);
        }
    }

    /**
     * Keep the heading-reference spelling only while the link still resolves by it.
     *
     * Same hazard as confirmAutolink(), one attribute over. A collapsed
     * `[text][]` resolves against the heading whose RENDERED TEXT equals the
     * label, and `ref` holds exactly that text. An editor that retypes the
     * visible text has changed which heading the reference would find, so
     * writing the authored `[old text][]` back would publish a reference to a
     * heading the document no longer names - and silently discard the edit.
     * The flag is therefore a HINT to be re-derived, not truth: when the text
     * no longer matches, the link falls back to its inline form, which always
     * renders correctly.
     *
     * The bound this leaves is deliberate rather than overlooked. An edit that
     * changes only the MARKUP inside the label - unbolding `[*bold* heading][]`
     * - keeps the rendered text, so the reference still resolves to the same
     * heading and the authored markup is restored with it. That is a real loss
     * of the edit, and it is the lesser of the two available ones: the
     * alternative is writing `[bold heading](#bold-heading)`, which bakes a
     * generated id into the source on every pass, which is the loss
     * `Link::$fromHeadingReference` exists to prevent.
     */

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<string, array{0: string, 1: \MarkupCarve\Carve\Parser\ReferenceDefinition}> $headings
     */
    protected function confirmHeadingReferences(Node $node, array $headings): void
    {
        if ($node instanceof Link) {
            $this->confirmHeadingReference($node, $headings);
        }

        foreach ($node->getChildren() as $child) {
            $this->confirmHeadingReferences($child, $headings);
        }
    }

    /**
     * @param \MarkupCarve\Carve\Node\Inline\Link $link
     * @param array<string, array{0: string, 1: \MarkupCarve\Carve\Parser\ReferenceDefinition}> $headings
     */
    protected function confirmHeadingReference(Link $link, array $headings): void
    {
        if (!$link->isFromHeadingReference()) {
            return;
        }

        $label = $this->headingReferenceLabel($link);
        foreach ($headings as [$headingLabel, $definition]) {
            // BOTH halves, because either one alone leaves an edit to the other
            // silently discarded. Text-only would keep the spelling after the
            // destination was repointed, and the writer emits the reference
            // rather than the href - so `[plain heading][]` came back and the
            // new URL was gone, which is carve-php#516 arriving through the
            // editor. Destination-only would keep it after the text changed,
            // which republishes a reference to a heading the document no longer
            // names.
            if ($headingLabel === $label && $definition->url === $link->getDestination()) {
                return;
            }
        }

        $this->setState($link, 'fromHeadingReference', false);
        $this->setState($link, 'referenceLabel', null);
        $this->setState($link, 'rawReferenceLabel', null);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     *
     * @return array<string, string> label => href
     */
    protected function collectLinkReferenceDefinitions(Node $node): array
    {
        $definitions = [];
        if ($node instanceof LinkReferenceDefinition) {
            $definitions[LabelKey::normalize($node->getLabel())] = $node->getHref();
        }

        // Link definitions are last-wins after normalization, as on reparse.
        foreach ($node->getChildren() as $child) {
            foreach ($this->collectLinkReferenceDefinitions($child) as $label => $href) {
                $definitions[$label] = $href;
            }
        }

        return $definitions;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<string, string> $definitions label => href
     */
    protected function confirmLabelReferences(Node $node, array $definitions): void
    {
        if ($node instanceof Link && !$node->isFromHeadingReference() && $node->getReferenceLabel() !== null) {
            $href = $definitions[LabelKey::normalize($node->getReferenceLabel())] ?? null;
            if ($href === null || $href !== $node->getDestination()) {
                $this->setState($node, 'referenceLabel', null);
                $this->setState($node, 'rawReferenceLabel', null);
            } else {
                $this->confirmRawSpelling($node, (new HeadingIdTracker())->getPlainText($node));
            }
        }

        // An image resolves by the same definitions; its destination is `src`.
        if ($node instanceof Image && $node->getReferenceLabel() !== null) {
            $href = $definitions[LabelKey::normalize($node->getReferenceLabel())] ?? null;
            if ($href === null || $href !== $node->getSource()) {
                $this->setState($node, 'referenceLabel', null);
                $this->setState($node, 'rawReferenceLabel', null);
            } else {
                $this->confirmRawSpelling($node, $node->getAlt());
            }
        }

        foreach ($node->getChildren() as $child) {
            $this->confirmLabelReferences($child, $definitions);
        }
    }

    /**
     * Keep the VERBATIM raw spelling only while its text half still says what
     * the node says.
     *
     * The writer emits `rawReferenceLabel` byte for byte, text half included -
     * so an editor that retyped the visible text of `[old][lbl]` while the
     * attrs rode along would come back as `[old][lbl]`, the edit silently
     * discarded. Same hazard as confirmAutolink(), one field over. The label
     * itself survives this check: with the raw gone the writer builds
     * `[text][label]` from the CURRENT text, which keeps both the edit and the
     * reference spelling. Only the exact authored bytes (spacing, an attribute
     * block written at the reference) are given up, and only when the text no
     * longer matches them.
     *
     * The raw's text half is compared as PLAIN TEXT, through the same parse
     * the label check uses, so `[*bold* x][l]` is not thrown away for merely
     * containing markup. A raw whose bracket structure cannot be walked - a
     * code span holding an unbalanced bracket - is dropped rather than
     * trusted, which always renders correctly.
     */
    protected function confirmRawSpelling(Link|Image $node, string $currentText): void
    {
        $raw = $node->getRawReferenceLabel();
        if ($raw === null) {
            return;
        }

        $inner = self::bracketedTextHalf($raw);
        $rawPlain = null;
        if ($inner !== null) {
            $parsed = (new CarveConverter())->parse($inner);
            $rawPlain = (new HeadingIdTracker())->getPlainText($parsed);
        }

        $normalize = static fn (string $s): string => preg_replace('/\s+/', ' ', trim($s)) ?? $s;
        if ($rawPlain === null || $normalize($rawPlain) !== $normalize($currentText)) {
            $this->setState($node, 'rawReferenceLabel', null);
        }
    }

    /**
     * The source between a reference's first bracket pair - the text half of
     * `[text][label]` / `![alt][label]` - or null when the brackets do not
     * balance.
     */
    protected static function bracketedTextHalf(string $raw): ?string
    {
        $start = strpos($raw, '[');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($raw);
        for ($i = $start; $i < $length; $i++) {
            $char = $raw[$i];
            if ($char === '\\') {
                $i++;

                continue;
            }
            if ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($raw, $start + 1, $i - $start - 1);
                }
            }
        }

        return null;
    }

    /**
     * The label a collapsed reference over this text would resolve by.
     *
     * Deliberately the RESOLVER's own two steps rather than a second spelling
     * of them: HeadingReferenceCollector registers a heading under
     * `HeadingIdTracker::getPlainText()` collapsed to single spaces, so asking
     * the same pair here is what makes this check agree with resolution by
     * construction. A local text walk would have to re-derive, among other
     * things, that a code span's text lives on the node instead of in children
     * - which it would get wrong, and the check would then reject every
     * reference whose label holds one.
     */
    protected function headingReferenceLabel(Link $link): string
    {
        $plainText = (new HeadingIdTracker())->getPlainText($link);

        return preg_replace('/\s+/', ' ', trim($plainText)) ?? $plainText;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param mixed $marks
     */
    protected function wrapInMarks(Node $node, mixed $marks): Node
    {
        if (!is_array($marks) || $marks === []) {
            return $node;
        }

        // ProseMirror lists marks outermost-first, so wrap in reverse to keep the
        // innermost mark closest to the text.
        foreach (array_reverse($marks) as $entry) {
            if (!is_array($entry) || !isset($entry['type']) || !is_string($entry['type'])) {
                continue;
            }

            $markType = $entry['type'];
            /** @var array<string, mixed> $mark */
            $mark = $entry;
            if ($markType === 'carveAbbreviation') {
                $markAttrs = is_array($mark['attrs'] ?? null) ? $mark['attrs'] : [];
                $title = self::asString($markAttrs['title'] ?? '');
                // Matched by TERM, not by expansion alone. An authored
                // `[foo]{abbr="HyperText Markup Language"}` beside a
                // `*[HTML]: HyperText Markup Language` definition shares the
                // expansion and is not that abbreviation - rebuilding it as one
                // wrote back plain `foo` and lost the span entirely.
                $term = self::plainTextOf($node);
                if ($term !== '' && ($this->incomingAbbreviations[$term] ?? null) === $title) {
                    $wrapper = new Abbreviation($title);
                } else {
                    $wrapper = new Span();
                    $wrapper->setAttribute('abbr', $title);
                    if (array_key_exists('carveKeyValues', $markAttrs)) {
                        $this->applyKeyValues($wrapper, $markAttrs['carveKeyValues']);
                    }
                }
            } else {
                $wrapper = $this->instantiate($markType, $mark);
                $this->applyAttributes($wrapper, $mark);
            }
            $wrapper->appendChild($node);
            if ($wrapper instanceof Link) {
                $this->confirmAutolink($wrapper);
            }
            $node = $wrapper;
        }

        return $node;
    }

    /**
     * The construct a `carveEmptyMark` stands for, rebuilt with no children.
     *
     * The atom is a wire node, not a Carve type - the map declares it under
     * `markCarrierNodes` - so it is read by the mark it NAMES rather than
     * through the type table. A name the map does not admit as a `markType`
     * would otherwise become an empty span and silently change the document,
     * so it is refused the same way an unknown node name is
     * (markup-carve/carve-grammars#240).
     *
     * @param string $markType
     * @param array<string, mixed> $markAttrs
     *
     * @throws \RuntimeException When the mark is not one the map names.
     */
    protected function emptyMarkNode(string $markType, array $markAttrs): Node
    {
        if (!in_array($markType, ['link', 'carveSpan', 'carveAbbreviation', 'carveInsert', 'carveDelete'], true)) {
            throw new RuntimeException(sprintf(
                'carveEmptyMark stands for a mark, and "%s" is not one the schema map names: '
                    . 'expected link, carveSpan, carveAbbreviation, carveInsert or carveDelete',
                $markType,
            ));
        }

        if ($markType === 'carveAbbreviation') {
            // `[]{abbr="..."}` as written. There is no term to match a
            // definition against here, so the authored span is the only shape
            // it can come back as.
            $node = new Span();
            $node->setAttribute('abbr', self::asString($markAttrs['title'] ?? ''));
            unset($markAttrs['title']);
            $this->applyAttributes($node, ['attrs' => $markAttrs]);

            return $node;
        }

        $node = $this->instantiate($markType, ['attrs' => $markAttrs]);
        $this->applyAttributes($node, ['attrs' => $markAttrs]);

        return $node;
    }

    /**
     * @param string $proseMirrorName
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException When the name has no Carve type.
     */
    protected function instantiate(string $proseMirrorName, array $data): Node
    {
        // A registration wins over the map, so an application can also override
        // a stock name its editor spells differently.
        $factory = $this->factories[$proseMirrorName] ?? null;
        if ($factory !== null) {
            return $factory($data);
        }

        // The PRESERVATION nodes another bridge writes for a construct it has
        // no editable node for. They are on the wire (the map's
        // `preservationNodes`), not Carve types, and every path that can meet
        // an inline node can meet one - a table cell among them, which is where
        // three corpus documents still threw after the inline path learned it.
        // Read from the map's own section rather than listed again here: the
        // map has THREE sections a bridge answers to, and a name restated in
        // code is a copy that stops being one the moment upstream adds a
        // fourth atom - which is exactly how `carveEmptyMark` arrived.
        if ((SchemaMap::carrierNames()[$proseMirrorName] ?? null) === 'preservationNodes') {
            $attrs = is_array($data['attrs'] ?? null) ? $data['attrs'] : [];

            return new RawText(self::asString($attrs['carveSource'] ?? ''));
        }

        $carveType = SchemaMap::carveTypeFor($proseMirrorName);
        if ($carveType === null) {
            throw new RuntimeException(sprintf(
                'ProseMirror node "%s" is not in the schema map. Add it upstream in carve-grammars '
                    . 'if it is a name every editor shares, or register it on this converter with '
                    . '%s::register() if it is your application\'s own.',
                $proseMirrorName,
                self::class,
            ));
        }

        if ($carveType === 'thematic_break') {
            $attrs = is_array($data['attrs'] ?? null) ? $data['attrs'] : [];
            $marker = self::asString($attrs['carveMarker'] ?? '-');
            $node = new ThematicBreak(in_array($marker, ['-', '*', '_'], true) ? $marker : '-');
        } else {
            $node = $this->newNode($carveType);
        }

        // State the map records as several ProseMirror names has to be put back:
        // the name is the only place that information survives.
        if ($node instanceof ListBlock) {
            // `task` is its own list type, not a bullet list whose items happen
            // to carry markers. Folding it to `bullet` made a task list and the
            // plain list beside it the same type, so the writer separated the
            // two with the indented-second-list spelling - and the one-space
            // indent moved the plain list to a different content column on
            // reparse (carve-php#1287).
            $this->setState($node, 'listType', match ($proseMirrorName) {
                'orderedList' => 'ordered',
                'taskList' => 'task',
                default => 'bullet',
            });
        } elseif ($node instanceof ListItem && $proseMirrorName === 'taskItem') {
            $itemAttrs = is_array($data['attrs'] ?? null) ? $data['attrs'] : [];
            $checked = self::asBool($itemAttrs['checked'] ?? false);
            $this->setState($node, 'taskMarker', $checked ? 'x' : ' ');
        } elseif ($node instanceof Mention && $proseMirrorName === 'carveTag') {
            // The name is the only place the flavor survives: the map resolves
            // carveTag back to `mention`, and a Mention with no class reads as a
            // link. carve-grammars sends this shape for every `#tag`, and
            // without the class it came back spelled `@tag` - a different
            // sigil, a different concept, and nothing reported.
            $this->setState($node, 'cssClass', 'tag');
        } elseif ($node instanceof Mention && $proseMirrorName === 'mention') {
            // The stock spelling brings the stock shape: tiptap's mention is an
            // atom with `id`/`label` attrs and no css class, where CarveKit's
            // node carries a class and its text as a child. Only the class can
            // be settled here (the label needs the attrs pass); without it the
            // node renders `class=""` and reads as a link, not a mention.
            $stock = is_array($data['attrs'] ?? null) ? $data['attrs'] : [];
            if (!array_key_exists('cssClass', $stock)) {
                $this->setState($node, 'cssClass', 'mention');
            }
        } elseif ($node instanceof TableCell && $proseMirrorName === 'tableHeader') {
            $this->setState($node, 'isHeader', true);
        } elseif ($node instanceof Div) {
            $class = match ($proseMirrorName) {
                'carveTabSet' => 'tabs',
                'carveTab' => 'tab',
                default => null,
            };
            if ($class !== null) {
                $node->setAttribute('class', $class);
            }
        }

        return $node;
    }

    /**
     * Editor attrs back onto the node: the typed state each class needs, then
     * every remaining key as a Carve attribute, which is how an application's
     * `data-*` keys survive.
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<string, mixed> $data
     */
    protected function applyAttributes(Node $node, array $data): void
    {
        $attrs = $data['attrs'] ?? [];
        if (!is_array($attrs)) {
            return;
        }

        $consumed = [];
        foreach ($attrs as $key => $value) {
            $consumed[$key] = match (true) {
                $node instanceof Heading && $key === 'level' => $this->setState($node, 'level', self::asInt($value)),
                $node instanceof CodeBlock && $key === 'language' => $this->setState($node, 'language', self::asString($value)),
                // The fence's own metadata, consumed here so it is restored to
                // the construct instead of being left in the author attribute
                // map (carve-php#519). A code block's structural title used to
                // arrive as a plain `title` attribute, which came back as BOTH
                // the fence's quoted title and an added `{title=...}` line
                // above it; its `[label]` had nothing carrying it at all.
                $node instanceof CodeBlock && in_array($key, ['carveHeader', 'carveFenceTitle'], true)
                    => $this->setState($node, 'header', self::asString($value)),
                $node instanceof CodeBlock && in_array($key, ['carveLabel', 'carveFenceLabel'], true)
                    => $this->setState($node, 'label', self::asString($value)),
                // Only when carveFenceTitle is ABSENT. A payload predating it
                // put the fence's title in `title`, so that is the best guess
                // available; but when both are present the `title` is the
                // AUTHOR's, from an attribute line, and it must stay an
                // attribute rather than overwrite the fence header - which is
                // its own document, not a spelling:
                //
                //   {title="from the attribute line"}
                //   ``` php "from the header"
                //
                // has two titles on purpose.
                $node instanceof CodeBlock && $key === 'title'
                    && !array_key_exists('carveHeader', $attrs)
                    && !array_key_exists('carveFenceTitle', $attrs) => $this->setState($node, 'header', self::asString($value)),
                $node instanceof ListBlock && $key === 'start' => $this->setState($node, 'start', self::asInt($value)),
                $node instanceof ListBlock && in_array($key, ['carveTight', 'tight'], true) => $this->setState($node, 'tight', self::asBool($value)),
                // PART 9 §17 L7's consumed `loose` boolean. `loose` is accepted
                // beside the prefixed spelling because that is the name PART 12
                // §8 gives the field, so a payload built from an AST tree reads
                // back without a rename.
                $node instanceof DefinitionList && in_array($key, ['carveLoose', 'loose'], true)
                    => $this->setState($node, 'loose', self::asBool($value)),
                $node instanceof ListBlock && $key === 'carveBareMarker' => $this->setState($node, 'bareMarker', self::asBool($value)),
                $node instanceof ListBlock && in_array($key, ['carveOlType', 'carveListStyle'], true) => $this->setState($node, 'style', self::asString($value)),
                $node instanceof ListBlock && in_array($key, ['carveDelim', 'carveListMarker'], true) => $this->setState($node, 'marker', self::asString($value)),
                $node instanceof ThematicBreak && $key === 'carveMarker' => true,
                $node instanceof BlockQuote && $key === 'carveFenced' => $this->setState(
                    $node,
                    'fenced',
                    self::asBool($value),
                ),
                $node instanceof TableCell && $key === 'textAlign' && in_array(
                    self::asString($value),
                    [TableCell::ALIGN_LEFT, TableCell::ALIGN_CENTER, TableCell::ALIGN_RIGHT],
                    true,
                ) => $this->setState($node, 'alignment', self::asString($value))
                    && $this->setState($node, 'hasExplicitAlignment', true),
                $node instanceof TableCell && in_array($key, ['verticalAlign', 'verticalAlignment'], true)
                    && in_array(self::asString($value), [TableCell::VALIGN_TOP, TableCell::VALIGN_MIDDLE, TableCell::VALIGN_BOTTOM], true)
                    => $this->setState($node, 'verticalAlignment', self::asString($value))
                    && $this->setState($node, 'hasExplicitVerticalAlignment', true),
                $node instanceof TableCell && $key === 'colspan' => $this->setState($node, 'colspan', self::asInt($value)),
                $node instanceof TableCell && $key === 'rowspan' => $this->setState($node, 'rowspan', self::asInt($value)),
                $node instanceof TableCell && $key === 'carveSpanMarker' => $this->setState(
                    $node,
                    'spanMarker',
                    is_scalar($value) ? (string)$value : null,
                ),
                // An `alignment` on the wire IS the cell's own marker: the
                // renderer publishes it only where the cell carries one, which
                // is also how carve-rs reads it back. Recording that keeps the
                // marker in the written form even where the column already
                // aligns the same way.
                $node instanceof TableCell && $key === 'alignment' => $this->setState($node, 'alignment', self::asString($value))
                    && $this->setState($node, 'hasExplicitAlignment', true),
                $node instanceof Image && $key === 'src' => $this->setState($node, 'source', self::asString($value)),
                $node instanceof Image && $key === 'alt' => $this->setState($node, 'alt', self::asString($value)),
                $node instanceof Link && $key === 'href' => $this->setState($node, 'destination', self::asString($value)),
                $node instanceof Link && $key === 'carveAutolink' => $this->setState($node, 'isAutolink', self::asBool($value)),
                // The reference spelling, restored to the construct rather than
                // left in the author attribute map - where it would come back
                // as a stray `{carveRawRef="[x][]"}` beside a destination the
                // author never wrote. The renderer emits these three together
                // and only for a heading reference, so each one restores the
                // field the canonical writer reads (carve-php#1006).
                $node instanceof Link && $key === 'carveHeadingRef' => $this->setState(
                    $node,
                    'fromHeadingReference',
                    self::asBool($value),
                ),
                ($node instanceof Link || $node instanceof Image) && $key === 'carveRef' => $this->setState($node, 'referenceLabel', self::asString($value)),
                ($node instanceof Link || $node instanceof Image) && $key === 'carveRawRef' => $this->setState($node, 'rawReferenceLabel', self::asString($value)),
                $node instanceof Math && $key === 'src' => $this->setState($node, 'content', self::asString($value)),
                $node instanceof Math && $key === 'display' => $this->setState($node, 'display', self::asBool($value)),
                $node instanceof Div && $key === 'label' => $this->setState($node, 'label', self::asString($value)),
                $node instanceof Div && $key === 'title' => $this->setState($node, 'header', self::asString($value)),
                $node instanceof Div && $key === 'carveTyped' => $this->setState($node, 'typed', self::asBool($value)),
                $node instanceof Div && $key === 'carveAttrs' => $this->applyCarveAttrs($node, $value),
                $node instanceof Abbreviation && $key === 'title' => $this->setState($node, 'title', self::asString($value)),
                $node instanceof InlineExtension && in_array($key, ['name', 'carveSource'], true) => $this->setState(
                    $node,
                    'extensionType',
                    ltrim(self::asString($value), ':'),
                ),
                ($node instanceof FootnoteRef || $node instanceof Footnote) && $key === 'label' => $this->setState($node, 'label', self::asString($value)),
                ($node instanceof RawBlock || $node instanceof RawInline) && $key === 'format' => $this->setState($node, 'format', self::asString($value)),
                ($node instanceof RawInline || $node instanceof LiteralInline || $node instanceof Comment || $node instanceof Frontmatter)
                    && $key === 'content' => $this->setState($node, 'content', self::asString($value)),
                // The recorded width is a writer's concern and a floor it widens
                // anyway, so the flag alone decides the spelling: a fenced
                // comment gets the minimum width back.
                $node instanceof Comment && $key === 'block' => $this->setState($node, 'fenceLength', self::asBool($value) ? 3 : null),
                // The `{% x %}` form (PART 9 section 21a). Unconsumed it would
                // become an author attribute AND leave the comment spelled
                // `%%`, which runs to end of line - so the text after it in the
                // paragraph is deleted, not re-spelled.
                $node instanceof Comment && $key === 'delimited' => $this->setState($node, 'delimited', self::asBool($value)),
                // CarveKit's line-block mode marker is editor bookkeeping.
                $node instanceof LineBlock && $key === 'mode' => true,
                $node instanceof Frontmatter && $key === 'format' => $this->setState($node, 'format', self::asString($value)),
                $node instanceof LinkReferenceDefinition && $key === 'label' => $this->setState($node, 'label', self::asString($value)),
                $node instanceof LinkReferenceDefinition && $key === 'href' => $this->setState($node, 'href', self::asString($value)),
                $node instanceof LinkReferenceDefinition && $key === 'title' => $this->setState($node, 'title', self::asString($value)),
                $node instanceof Symbol && $key === 'name' => $this->setState($node, 'name', self::asString($value)),
                $node instanceof Substitution && $key === 'oldText' => $this->setState($node, 'oldText', self::asString($value)),
                $node instanceof Substitution && $key === 'newText' => $this->setState($node, 'newText', self::asString($value)),
                $node instanceof HeadingRef && $key === 'target' => $this->setState($node, 'targetId', self::asString($value)),
                $node instanceof CitationGroup && $key === 'raw' => $this->setState($node, 'raw', self::asString($value)),
                $node instanceof CitationGroup && $key === 'integral' => $this->setState($node, 'integral', self::asBool($value)),
                $node instanceof CitationGroup && $key === 'items' => $this->applyCitationItems($node, $value),
                $node instanceof Mention && $key === 'cssClass' => $this->setState($node, 'cssClass', self::asString($value)),
                $node instanceof Link && $key === 'carveReferenceDefinition' => true,
                $key === 'carveKeyValues' => true,
                // Replayed after the loop below, once every slot it names has
                // actually been set on the node.
                $key === 'carveAttrOrder' => true,
                // A mention's visible name is a child Text node here, but
                // tiptap/extension-mention is an atom that keeps it in `label`
                // (`id` when unlabelled). Left as an attribute it becomes a
                // stray `label="Alice"` and the mention renders with nothing to
                // show - so it drops out of Carve source entirely. CarveKit's
                // own carveMention never sends `label`, so consuming it cannot
                // collide. The sigil is tiptap's default `@`; an editor
                // configured for `#` registers its own factory.
                $node instanceof Mention && $key === 'label' && !self::hasContent($data) => $this->addMentionLabel(
                    $node,
                    self::asString($value),
                ),
                $node instanceof Mention && $key === 'id' && !self::hasContent($data)
                    && !array_key_exists('label', $attrs) => $this->addMentionLabel($node, self::asString($value)),
                ($node instanceof Image || $node instanceof Link) && $key === 'title' => $this->setState($node, 'title', self::asString($value)),
                // Editor bookkeeping that has no Carve meaning.
                in_array($key, ['checked', 'languageRaw'], true) => true,
                default => false,
            };
        }

        foreach ($attrs as $key => $value) {
            if ($key === 'carveKeyValues') {
                $this->applyKeyValues($node, $value);

                continue;
            }
            if (($consumed[$key] ?? false) === true) {
                continue;
            }
            if (is_scalar($value)) {
                $node->setAttribute((string)$key, self::asString($value));

                continue;
            }
            // A Carve attribute value is a string, so a non-scalar has no form
            // here. `null` is the editor's way of saying "unset", so it carries
            // nothing to lose; anything else does, and used to fall off the end
            // of this loop without a word. Tiptap's resizable table stores
            // `colwidth` as an array, which is the case with a real producer
            // behind it.
            //
            // Reported rather than encoded: a joined string would come back as
            // a string and not an array, and a JSON-encoded one would put an
            // unauthorable value in source. Which of those is right is a design
            // question (carve-php#541); being silent is not.
            if ($value !== null) {
                $this->droppedAttributes[(string)$key] = sprintf(
                    'a Carve attribute holds a string, and this value is of type %s',
                    get_debug_type($value),
                );
            }
        }

        // The authored run, in the order it was WRITTEN. Applied last because
        // every setAttribute() above appends its own slot, so an order set
        // earlier would be overwritten by the storage order it is there to
        // correct. The writer already knows what to do with it: replay each
        // named slot in sequence, skip one the document no longer has, and
        // emit anything the order does not name after the ones it does.
        if (array_key_exists('carveAttrOrder', $attrs)) {
            $order = $this->attributeOrderFrom($attrs['carveAttrOrder']);
            if ($order !== []) {
                $node->setAttributeOrder($order);
            }
        }

        // Older payloads did not carry whether a `carveDiv` was opened with a
        // type word. Keep the historical single-class heuristic for those only.
        if ($node instanceof Div && !array_key_exists('carveTyped', $attrs) && count($node->getClassList()) >= 1) {
            $this->setState($node, 'typed', true);
        }
    }

    /**
     * The slot list from a `carveAttrOrder`, with anything that is not a slot
     * name dropped.
     *
     * @return list<string>
     */
    private function attributeOrderFrom(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    private function applyKeyValues(Node $node, mixed $value): bool
    {
        if (!is_array($value)) {
            return true;
        }
        foreach ($value as $key => $item) {
            if (is_scalar($item)) {
                $node->setAttribute((string)$key, self::asString($item));
            }
        }

        return true;
    }

    /**
     * A citation item's prefix, locator and suffix arrive as ProseMirror
     * inline arrays - the renderer emits them with its normal inline path, so
     * they are rebuilt with this converter's. An entry without a string key is
     * not an item and is skipped rather than coerced into one.
     *
     * @param \MarkupCarve\Carve\Node\Inline\CitationGroup $node
     * @param mixed $value
     */
    protected function applyCitationItems(CitationGroup $node, mixed $value): bool
    {
        if (!is_array($value)) {
            return true;
        }

        $items = [];
        foreach ($value as $entry) {
            if (!is_array($entry) || !is_string($entry['key'] ?? null)) {
                continue;
            }
            $item = [
                'key' => $entry['key'],
                'suppressAuthor' => self::asBool($entry['suppressAuthor'] ?? false),
            ];
            foreach (['prefix', 'locator', 'suffix'] as $inlineField) {
                if (!is_array($entry[$inlineField] ?? null)) {
                    continue;
                }
                /** @var array<int, array<string, mixed>> $payloads */
                $payloads = array_values(array_filter($entry[$inlineField], 'is_array'));
                $inlines = [];
                foreach ($payloads as $payload) {
                    foreach ($this->buildInlines($payload) as $built) {
                        if ($built instanceof InlineNode) {
                            $inlines[] = $built;
                        }
                    }
                }
                if ($inlines !== []) {
                    $item[$inlineField] = $inlines;
                }
            }
            foreach (['locatorLabel', 'locatorValue'] as $stringField) {
                if (is_string($entry[$stringField] ?? null)) {
                    $item[$stringField] = $entry[$stringField];
                }
            }
            $items[] = $item;
        }
        $node->setItems($items);

        return true;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Block\Div $node
     * @param mixed $value
     */
    protected function applyCarveAttrs(Div $node, mixed $value): bool
    {
        if (!is_array($value)) {
            return true;
        }

        $attributes = [];
        $order = [];
        foreach ($value as $key => $attributeValue) {
            if (!is_string($key) || !is_scalar($attributeValue)) {
                continue;
            }
            $attributes[$key] = self::asString($attributeValue);
            if ($key === 'id') {
                $order[] = '#id';
            } elseif ($key === 'class') {
                $order[] = '.class';
            } else {
                $order[] = $key;
            }
        }

        $node->setAttributesWithOrder($attributes, $order);

        return true;
    }

    private static function asString(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    private static function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    private static function asBool(mixed $value): bool
    {
        return is_scalar($value) && (bool)$value;
    }

    /**
     * Node state lives in protected properties with no setters for most classes,
     * so it is written by reflection - the same mechanism AstCodec decodes with.
     */

    /**
     * Whether the payload carries children of its own, so a label attribute
     * would duplicate text that is already there rather than supply missing
     * text. A stock mention is an atom and never does; a hand-built payload
     * with both should keep what it spelled out.
     *
     * @param array<string, mixed> $data
     */
    protected static function hasContent(array $data): bool
    {
        return is_array($data['content'] ?? null) && $data['content'] !== [];
    }

    /**
     * The visible name as the child Text node a Mention carries it in.
     */
    protected function addMentionLabel(Mention $node, string $label): bool
    {
        if ($label === '') {
            return false;
        }

        // The sigil follows the flavor the class records, not the stock `@`.
        // A carveTag arrives with its name in `id` and no sigil anywhere, so
        // hardcoding `@` here rewrote every tag into a mention.
        $sigil = $node->getCssClass() === 'tag' ? '#' : '@';
        $node->appendChild(new Text(str_starts_with($label, $sigil) ? $label : $sigil . $label));

        return true;
    }

    protected function setState(Node $node, string $property, mixed $value): bool
    {
        $reflection = new ReflectionClass($node);
        if (!$reflection->hasProperty($property)) {
            return false;
        }

        $reflection->getProperty($property)->setValue($node, $value);

        return true;
    }

    /**
     * The single IMAGE a figure's paragraph wrapper holds, or null when the
     * paragraph is anything else.
     *
     * @param array<string, mixed> $paragraph
     *
     * @return array<string, mixed>|null
     */
    private function loneParagraphImage(array $paragraph): ?array
    {
        $children = $this->childrenOf($paragraph);
        if (count($children) !== 1) {
            return null;
        }
        $only = $children[0];

        return ($only['type'] ?? null) === 'image' ? $only : null;
    }

    /**
     * The plain text a built inline carries, for matching an abbreviation to
     * the definition that expanded it.
     */
    private static function plainTextOf(Node $node): string
    {
        if ($node instanceof Text) {
            return $node->getContent();
        }
        $text = '';
        foreach ($node->getChildren() as $child) {
            $text .= self::plainTextOf($child);
        }

        return $text;
    }

    protected function newNode(string $carveType): Node
    {
        $class = self::CLASS_MAP[$carveType] ?? null;
        if ($class === null) {
            throw new RuntimeException(sprintf('No node class for Carve type "%s"', $carveType));
        }

        $reflection = new ReflectionClass($class);
        /** @var \MarkupCarve\Carve\Node\Node $node */
        $node = $reflection->newInstanceWithoutConstructor();

        // Bypassing the constructor leaves typed properties uninitialized, which
        // throws on first read; give each its declared or constructor default.
        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic() || $property->isInitialized($node)) {
                continue;
            }
            if ($property->hasDefaultValue()) {
                $property->setValue($node, $property->getDefaultValue());

                continue;
            }
            $default = null;
            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                if ($parameter->getName() === $property->getName() && $parameter->isDefaultValueAvailable()) {
                    $default = $parameter->getDefaultValue();

                    break;
                }
            }
            $type = $property->getType();
            if ($default === null && $type instanceof ReflectionNamedType && !$type->allowsNull()) {
                $default = match ($type->getName()) {
                    'array' => [],
                    'bool' => false,
                    'int' => 0,
                    'float' => 0.0,
                    'string' => '',
                    default => null,
                };
            }
            $property->setValue($node, $default);
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<int, array<string, mixed>>
     */
    protected function childrenOf(array $data): array
    {
        $content = $data['content'] ?? [];
        if (!is_array($content)) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $children */
        $children = array_values(array_filter($content, 'is_array'));

        return $children;
    }

    /**
     * Carve type to node class. Only the types the map marks as mapped can
     * arrive here, so this covers the bridge's vocabulary rather than the whole
     * AST.
     *
     * @var array<string, class-string<\MarkupCarve\Carve\Node\Node>>
     */
    private const CLASS_MAP = [
        'paragraph' => Paragraph::class,
        'heading' => Heading::class,
        'text' => Text::class,
        'hard_break' => HardBreak::class,
        'block_quote' => BlockQuote::class,
        'code_block' => CodeBlock::class,
        'thematic_break' => ThematicBreak::class,
        'list' => ListBlock::class,
        'list_item' => ListItem::class,
        'table' => Table::class,
        'table_row' => TableRow::class,
        'table_cell' => TableCell::class,
        'image' => Image::class,
        'math' => Math::class,
        'footnote_ref' => FootnoteRef::class,
        'footnote' => Footnote::class,
        'div' => Div::class,
        'admonition' => Div::class,
        'definition_list' => DefinitionList::class,
        'definition_term' => DefinitionTerm::class,
        'definition_description' => DefinitionDescription::class,
        'mention' => Mention::class,
        'inline_extension' => InlineExtension::class,
        'figure' => Figure::class,
        'figure_group' => FigureGroup::class,
        'caption' => Caption::class,
        'section' => Section::class,
        'line_block' => LineBlock::class,
        'comment' => Comment::class,
        'frontmatter' => Frontmatter::class,
        'raw_block' => RawBlock::class,
        'link_reference_definition' => LinkReferenceDefinition::class,
        'inline_footnote' => InlineFootnote::class,
        'raw_inline' => RawInline::class,
        'literal_inline' => LiteralInline::class,
        'substitution' => Substitution::class,
        'symbol' => Symbol::class,
        'citation_group' => CitationGroup::class,
        'heading_ref' => HeadingRef::class,
        'strong' => Strong::class,
        'emphasis' => Emphasis::class,
        'underline' => Underline::class,
        'strike' => Strike::class,
        'code' => Code::class,
        'highlight' => Highlight::class,
        'subscript' => Subscript::class,
        'superscript' => Superscript::class,
        'insert' => Insert::class,
        'delete' => Delete::class,
        'span' => Span::class,
        'abbreviation' => Abbreviation::class,
        'link' => Link::class,
        'autolink' => Link::class,
    ];
}
