<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\ProseMirror;

use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Abbreviation;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\CriticComment;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\InlineExtension;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\LiteralInline;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Inline\RawText;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Strong;
use MarkupCarve\Carve\Node\Inline\Symbol;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\TableSpanGrid;

/**
 * Renders a Carve AST as a ProseMirror (Tiptap) document.
 *
 * Names come from the published schema map, not from a table restated here, so
 * the output loads into an editor running CarveKit. See SchemaMap.
 *
 * Two shape differences do the real work:
 *
 * - **Marks.** Carve nests emphasis as elements (`Strong > Text`); ProseMirror
 *   hangs marks off the text node itself. Inline walking therefore carries the
 *   active mark set down and attaches it when it reaches text.
 * - **Type narrowing.** One Carve type can be several ProseMirror nodes - a
 *   `list` is a bulletList, orderedList or taskList - so the node's own state
 *   selects among the names the map lists.
 *
 * Types the editor model cannot represent are dropped rather than guessed at,
 * and every drop is recorded: `droppedTypes()` reports what a caller lost, which
 * is the difference between a bridge and a data-loss bug.
 */
class ProseMirrorRenderer
{
    /**
     * @var array<string, string> carve type => reason
     */
    protected array $dropped = [];

    /**
     * @var array<string, string> carve type => reason
     */
    protected array $degraded = [];

    /**
     * @return array<string, mixed>
     */
    public function render(Document $document): array
    {
        $this->dropped = [];
        $this->degraded = [];

        $doc = [
            'type' => 'doc',
            'content' => $this->renderBlocks($document->getChildren()),
        ];

        // Abbreviation definitions are DOCUMENT state, not children, so they
        // never reach renderBlocks and used to vanish across the bridge without
        // being reported (carve-php#519). The occurrence survives - it is a
        // `carveAbbreviation` mark carrying its title - but the definition it
        // came from does not, so the writer emits no `*[ABBR]: ...` line and the
        // next parse of that source has no abbreviation at all. Every expansion
        // in the document silently stops working.
        //
        // They ride on the doc node's attrs, which is where ProseMirror puts
        // document-level state. The ordering flag travels with them: it decides
        // whether the definitions are written before the body or after it, and
        // it is not recoverable from the map alone.
        $abbreviations = $document->getAbbreviations();
        if ($abbreviations !== []) {
            $doc['attrs'] = [
                'carveAbbreviations' => $abbreviations,
                'carveAbbreviationsBeforeBody' => $document->hasAbbreviationsBeforeBody(),
            ];
        }

        return $doc;
    }

    public function renderJson(Document $document, int $flags = 0): string
    {
        return (string)json_encode($this->render($document), $flags | JSON_THROW_ON_ERROR);
    }

    /**
     * What the last render could not represent, as type => reason.
     *
     * Empty means the document survived intact. A caller storing documents
     * should assert on this rather than trust it.
     *
     * @return array<string, string>
     */
    public function droppedTypes(): array
    {
        return $this->dropped;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     *
     * @return array<int, array<string, mixed>>
     */
    protected function renderBlocks(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $rendered = $this->renderBlock($node);
            if ($rendered !== null) {
                $out[] = $rendered;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function renderBlock(Node $node): ?array
    {
        $type = $node->getType();
        $this->noteUnrepresentableState($node);

        // A section is a rendering wrapper, not editor content: hoist its
        // children so the heading and body land at document level.
        if ($type === 'section') {
            $children = $this->renderBlocks($node->getChildren());

            return $children === [] ? null : ['type' => 'carveSection', 'content' => $children];
        }

        $name = $this->proseMirrorName($node);
        if ($name === null) {
            $this->dropped[$type] = SchemaMap::unmappedReason($type) ?? 'no ProseMirror name for this type';

            return null;
        }

        $out = ['type' => $name];
        $attrs = $this->attributesFor($node);
        if ($attrs !== []) {
            $out['attrs'] = $attrs;
        }

        if ($node instanceof Table && $node->hasCaption()) {
            // A caption hangs off the table as state rather than as a child, so
            // walking children alone loses it silently - the worst kind of loss,
            // since nothing would report it. Emit it as the leading child.
            $caption = $node->getCaption();
            if ($caption !== null) {
                $out['content'] = [
                    ['type' => 'carveCaption', 'content' => $this->renderInlines($caption->getChildren(), [])],
                    ...$this->renderTableRows($node),
                ];

                return $out;
            }
        }

        if ($node instanceof CodeBlock) {
            // The text lives in a property, not in children, so it has to be
            // emitted as the single text child ProseMirror expects.
            $code = $node->getContent();
            $content = $code === '' ? [] : [['type' => 'text', 'text' => $code]];
        } elseif ($node instanceof Table) {
            $content = $this->renderTableRows($node);
        } else {
            $content = $this->isInlineContainer($node)
                ? $this->renderInlines($node->getChildren(), [])
                : $this->renderBlocks($node->getChildren());
        }

        if ($content !== []) {
            $out['content'] = $content;
        }

        return $out;
    }

    /**
     * A table's rows, with a cell for every column the ProseMirror editor
     * model actually wants - one per NON-consumed grid entry, carrying the
     * rowspan/colspan a span marker resolves to.
     *
     * carve-php#527: this engine's own tree keeps a real, empty `table_cell`
     * for every `^`/`<` marker (carve-js parity, uniform row width), but
     * ProseMirror's table is the OTHER shape - a merged cell with its own
     * `colspan`/`rowspan`, and no node at all for the columns it covers.
     * Walking `TableRow::getChildren()` node-for-node would therefore emit an
     * extra `tableCell` for every placeholder, corrupting the editor's grid
     * exactly the way it would corrupt HTML (`renderTable` in HtmlRenderer)
     * or the Carve/ANSI/Markdown output (`TableSpanGrid` / `TableLayout`) -
     * so this resolves the same grid those do and skips a consumed entry
     * entirely rather than rendering it.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function renderTableRows(Table $node): array
    {
        $grid = TableSpanGrid::resolve($node);

        $tableRows = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof TableRow) {
                $tableRows[] = $child;
            }
        }

        $rows = [];
        foreach ($tableRows as $index => $row) {
            $this->noteUnrepresentableState($row);
            $rowOut = ['type' => 'tableRow'];
            // A row's own attributes. `renderTableRows` builds the row node
            // directly rather than going through the generic block path, which
            // is what attaches `attrs` everywhere else - so `{.head}` on a row
            // was dropped across a round trip (carve-php#557 introduced this,
            // carve-php#519 measured it).
            $rowAttrs = $this->attributesFor($row);
            if ($rowAttrs !== []) {
                $rowOut['attrs'] = $rowAttrs;
            }
            $cells = [];
            foreach ($grid[$index] as $entry) {
                $this->noteUnrepresentableState($entry['cell']);
                if ($entry['skip']) {
                    continue;
                }
                $cells[] = $this->renderResolvedTableCell($entry['cell'], $entry['rowspan'], $entry['colspan']);
            }
            if ($cells !== []) {
                $rowOut['content'] = $cells;
            }
            $rows[] = $rowOut;
        }

        return $rows;
    }

    /**
     * A single `tableCell`/`tableHeader` node with an EXPLICIT rowspan/colspan
     * resolved by `TableSpanGrid`, rather than the cell's own stored value -
     * see `renderTableRows`.
     *
     * @return array<string, mixed>
     */
    protected function renderResolvedTableCell(TableCell $cell, int $rowspan, int $colspan): array
    {
        $out = ['type' => $cell->isHeader() ? 'tableHeader' : 'tableCell'];
        $attrs = $this->attributesFor($cell);
        $attrs['colspan'] = $colspan;
        $attrs['rowspan'] = $rowspan;
        // A BLOCKED span marker is content, not a span. `| < | b |` has no cell
        // to its left to continue into, so the parser keeps the marker on the
        // cell and renders it empty - and an empty cell is all the editor saw,
        // so the writer had nothing to put back and the marker was lost in
        // silence (carve-php#519, class 7). The resolved spans above are
        // reconstructed from colspan/rowspan and were never the problem.
        //
        // `carveSpanMarker` follows `carveSource`: a lossless escape hatch for
        // the exact thing the author wrote, which the editor carries but does
        // not interpret.
        $marker = $cell->getSpanMarker();
        if ($marker !== null && $marker !== '') {
            $attrs['carveSpanMarker'] = $marker;
        }
        $out['attrs'] = $attrs;

        $content = $this->isInlineContainer($cell)
            ? $this->renderInlines($cell->getChildren(), [])
            : $this->renderBlocks($cell->getChildren());
        if ($content !== []) {
            $out['content'] = $content;
        }

        return $out;
    }

    /**
     * Inline content, flattening Carve's nested mark elements onto text nodes.
     *
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     * @param array<int, array<string, mixed>> $marks marks active from enclosing nodes
     *
     * @return array<int, array<string, mixed>>
     */
    protected function renderInlines(array $nodes, array $marks): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $type = $node->getType();
            $this->noteUnrepresentableState($node);

            if ($node instanceof Text) {
                $text = $node->getContent();
                if ($text === '') {
                    continue;
                }
                $textNode = ['type' => 'text', 'text' => $text];
                if ($marks !== []) {
                    $textNode['marks'] = $marks;
                }
                $out[] = $textNode;

                continue;
            }

            // Types the editor model has no node for, but whose CONTENT is plain
            // text: degrading them to text keeps the document readable, where
            // dropping them would run words together or lose a character. This is
            // recorded separately from a drop - the text survives, the node type
            // does not, so an AST round-trip will not be identical.
            $asText = $this->degradeToText($node);
            if ($asText !== null) {
                $this->degraded[$type] = SchemaMap::unmappedReason($type) ?? 'degraded to text';
                if ($asText !== '') {
                    $textNode = ['type' => 'text', 'text' => $asText];
                    if ($marks !== []) {
                        $textNode['marks'] = $marks;
                    }
                    $out[] = $textNode;
                }

                continue;
            }

            if ($node instanceof Code || $node instanceof CriticComment) {
                // Same asymmetry inline: Carve holds the text on the node, while
                // ProseMirror wants a text node carrying a mark. Both of these
                // have literal content and no children, so descending as a mark
                // would emit an empty element and lose the text.
                $out[] = [
                    'type' => 'text',
                    'text' => $node->getContent(),
                    'marks' => [...$marks, ['type' => (string)SchemaMap::nameFor($type)]],
                ];

                continue;
            }

            if (SchemaMap::isMark($type)) {
                $mark = ['type' => (string)SchemaMap::nameFor($type)];
                $attrs = $this->attributesFor($node);
                if ($attrs !== []) {
                    $mark['attrs'] = $attrs;
                }
                // Descend with this mark added rather than emitting a node.
                foreach ($this->renderInlines($node->getChildren(), [...$marks, $mark]) as $child) {
                    $out[] = $child;
                }

                continue;
            }

            $name = $this->proseMirrorName($node);
            if ($name === null) {
                $this->dropped[$type] = SchemaMap::unmappedReason($type) ?? 'no ProseMirror name for this type';

                continue;
            }

            $inline = ['type' => $name];
            $attrs = $this->attributesFor($node);
            if ($attrs !== []) {
                $inline['attrs'] = $attrs;
            }
            if ($marks !== []) {
                $inline['marks'] = $marks;
            }
            $children = $this->renderInlines($node->getChildren(), []);
            if ($children !== []) {
                $inline['content'] = $children;
            }
            $out[] = $inline;
        }

        return $out;
    }

    /**
     * The plain text an unmodeled inline stands for, or null when it has none.
     */
    protected function degradeToText(Node $node): ?string
    {
        return match (true) {
            $node instanceof SoftBreak => ' ',
            $node instanceof EscapedText => $node->getContent(),
            $node instanceof LiteralInline => $node->getContent(),
            $node instanceof RawText => $node->getContent(),
            $node instanceof SmartPunctuation => $node->getGlyph(),
            $node instanceof Symbol => $node->getName(),
            default => null,
        };
    }

    /**
     * Types the last render kept as text rather than as their own node.
     *
     * @return array<string, string>
     */
    public function degradedTypes(): array
    {
        return $this->degraded;
    }

    /**
     * Narrow a Carve type to one ProseMirror name using the node's own state.
     */
    protected function proseMirrorName(Node $node): ?string
    {
        $type = $node->getType();

        if ($node instanceof ListBlock) {
            if ($this->isTaskList($node)) {
                return 'taskList';
            }

            return $node->getListType() === 'ordered' ? 'orderedList' : 'bulletList';
        }

        if ($node instanceof ListItem) {
            return $node->isTask() ? 'taskItem' : 'listItem';
        }

        if ($node instanceof TableCell) {
            return $node->isHeader() ? 'tableHeader' : 'tableCell';
        }

        if ($node instanceof Div) {
            $class = (string)($node->getAttribute('class') ?? '');

            return match (true) {
                str_contains($class, 'tabs') => 'carveTabSet',
                str_contains($class, 'tab') => 'carveTab',
                default => 'carveDiv',
            };
        }

        return SchemaMap::nameFor($type);
    }

    protected function isTaskList(ListBlock $list): bool
    {
        foreach ($list->getChildren() as $item) {
            if ($item instanceof ListItem && $item->isTask()) {
                return true;
            }
        }

        return false;
    }

    /**
     * ProseMirror attrs for a node: the state the editor needs, plus the Carve
     * attribute map (id, class and every data-*), which is how an application's
     * own block types survive the trip.
     *
     * @return array<string, mixed>
     */
    protected function attributesFor(Node $node): array
    {
        $attrs = [];

        if ($node instanceof Heading) {
            $attrs['level'] = $node->getLevel();
        } elseif ($node instanceof CodeBlock) {
            // An empty info string is not a language: emitting it would render
            // class="language-".
            $language = (string)$node->getLanguage();
            if ($language !== '') {
                $attrs['language'] = $language;
            }
            // The fence's own metadata, kept apart from the author attribute
            // map (carve-php#519). Both were being lost or duplicated because
            // the two share a name:
            //
            //   ``` php [NPM]        the label vanished - nothing carried it
            //   ``` php "src/x.php"  came back with a `{title=src/x.php}`
            //                        attribute line ADDED above the fence,
            //                        because the structural title reached the
            //                        editor as a plain `title` attribute and
            //                        the writer then emitted it in both places
            //
            // Same shape as carveTyped / carveAttrs on a div: the payload has
            // to say which values are the construct's own.
            $header = $node->getHeader();
            if ($header !== null) {
                $attrs['carveFenceTitle'] = $header;
            }
            $fenceLabel = $node->getLabel();
            if ($fenceLabel !== null && $fenceLabel !== '') {
                $attrs['carveFenceLabel'] = $fenceLabel;
            }
        } elseif ($node instanceof ListBlock) {
            if ($node->getListType() === 'ordered') {
                $attrs['start'] = $node->getStart();
            }
            // Looseness decides whether items render their paragraphs, so it is
            // content, not styling: without it a loose list comes back tight.
            $attrs['tight'] = $node->isTight();
        } elseif ($node instanceof ListItem) {
            if ($node->isTask()) {
                $attrs['checked'] = $node->isCompleted();
            }
        } elseif ($node instanceof TableCell) {
            $attrs['colspan'] = $node->getColspan();
            $attrs['rowspan'] = $node->getRowspan();
            if ($node->getAlignment() !== TableCell::ALIGN_DEFAULT) {
                $attrs['alignment'] = $node->getAlignment();
            }
        } elseif ($node instanceof Image) {
            $attrs['src'] = $node->getSource();
            $attrs['alt'] = $node->getAlt();
            // An empty title is not the same as no title - corpus 108 pins that
            // `[x](u "")` keeps `title=""`.
            if ($node->getTitle() !== null) {
                $attrs['title'] = $node->getTitle();
            }
        } elseif ($node instanceof Mention) {
            // Mention extends Link, so this must come first or the Link branch
            // swallows it and the css class never reaches the editor.
            $attrs['href'] = $node->getDestination();
            $attrs['cssClass'] = $node->getCssClass();
        } elseif ($node instanceof Link) {
            $attrs['href'] = $node->getDestination();
            if ($node->getTitle() !== null) {
                $attrs['title'] = $node->getTitle();
            }
        } elseif ($node instanceof Math) {
            $attrs['src'] = $node->getContent();
            $attrs['display'] = $node->isDisplay();
        } elseif ($node instanceof FootnoteRef || $node instanceof Footnote) {
            // The label is the note's identity: it binds a reference to its
            // definition and distinguishes two references to the same note.
            // Without it every footnote in the document is the same anonymous
            // one. `carveFootnote` declares the attribute; only this side was
            // leaving it unset.
            $attrs['label'] = $node->getLabel();
        } elseif ($node instanceof Abbreviation) {
            // The expansion is the whole point of an abbreviation; without it
            // the mark says only "this was one" and the definition is gone.
            $attrs['title'] = $node->getTitle();
        } elseif ($node instanceof InlineExtension) {
            // `carveSource` is the schema's lossless escape hatch: the exact
            // directive the author wrote. Without it a `:kbd[x]` comes back as
            // `:[x]`, which is not valid Carve at all.
            $attrs['carveSource'] = ':' . $node->getExtensionType();
        } elseif ($node instanceof Div) {
            $label = $node->getLabel();
            if ($label !== null && $label !== '') {
                $attrs['label'] = $label;
            }
            // An empty title is meaningful - `::: note ""` suppresses the
            // default heading - so only a missing one is left unset. Dropping
            // it lost the container's heading outright, which is content, not
            // spelling.
            $header = $node->getHeader();
            if ($header !== null) {
                $attrs['title'] = $header;
            }
            $attrs['carveTyped'] = $node->isTyped();
            $attrs['carveAttrs'] = $node->getAttributes();
        }

        // Author attributes fill in around the structural ones; they never
        // replace them. A link's destination comes from the link syntax, and an
        // authored `{href=...}` is a plain attribute the HTML target already
        // refuses to promote - so letting it win here would hand the editor a
        // destination the document does not have, and writing that model back
        // out would make it the real one.
        foreach ($node->getAttributes() as $key => $value) {
            if (array_key_exists($key, $attrs)) {
                continue;
            }
            $attrs[$key] = $value;
        }

        return $attrs;
    }

    /**
     * Records state the editor model has no place for.
     *
     * A type can map cleanly and still lose something: the NODE survives, one
     * of its fields does not. Those losses were invisible - the type never
     * appeared in either report, because nothing was dropped or degraded to
     * text - so a caller storing documents had no way to find out. Each entry
     * names the field rather than the type alone.
     */
    protected function noteUnrepresentableState(Node $node): void
    {
        if ($node instanceof Link && !$node instanceof Mention) {
            if ($node->isAutolink()) {
                $this->degraded['autolink'] = 'an autolink is a plain link mark in the editor model, '
                    . 'so it comes back written as [text](url)';
            }
            if ($node->getChildren() === []) {
                // A mark needs text to attach to. An empty label has none, so
                // the link does not merely change shape - it disappears.
                $this->degraded['link'] = 'a link with an empty label has no text to carry the mark, '
                    . 'so it is not represented at all';
            }
            if (array_key_exists('href', $node->getAttributes())) {
                // Deliberately NOT carried: an authored `{href=...}` must never
                // reach the editor as a destination, because writing that model
                // back out would make it the document's real one - which is how
                // carve-php#516 rewrote a destination to an attacker-supplied
                // value. Dropping it is the safe choice, but it was a SILENT
                // one, so a caller storing documents could not tell the
                // attribute had gone (carve-php#519).
                $this->degraded['link'] = 'an authored href attribute is not carried, because promoting it '
                    . 'would change the link destination; it is dropped rather than round-tripped';
            }
        }

        if ($node instanceof Emphasis || $node instanceof Strong) {
            // `/*x*/` and `*/x/*` are the same tree; the editor model keeps
            // marks as a set, not an order, so the writer picks one spelling
            // and the author's is not recoverable. Rendering is unaffected -
            // which is why this needs declaring rather than fixing: it is
            // invisible in HTML and visible in the source (carve-php#519).
            foreach ($node->getChildren() as $child) {
                if ($child instanceof Emphasis || $child instanceof Strong) {
                    $this->degraded['emphasis'] = 'nested emphasis and strong are an unordered mark set in '
                        . 'the editor model, so the authored delimiter order is not preserved';

                    break;
                }
            }
        }

        if ($node instanceof Code && $node->getAttributes() !== []) {
            $this->degraded['code'] = 'inline code is a mark; its attributes have nowhere to live';
        }

        if ($node instanceof ListBlock) {
            if ($node->getStyle() !== null) {
                $this->degraded['list'] = 'an alphabetic or roman list style is not in the editor model, '
                    . 'so the list comes back numbered';
            } elseif ($node->getMarker() !== null && !in_array($node->getMarker(), ['-', '.'], true)) {
                $this->degraded['list'] = 'a list marker character is not in the editor model, '
                    . 'so the canonical one comes back';
            }
        }
    }

    protected function isInlineContainer(Node $node): bool
    {
        return in_array($node->getType(), [
            'paragraph',
            'heading',
            'table_cell',
            'definition_term',
            'caption',
        ], true);
    }
}
