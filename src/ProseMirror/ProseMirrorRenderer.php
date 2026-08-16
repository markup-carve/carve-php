<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\ProseMirror;

use MarkupCarve\Carve\Extension\Frontmatter;
use MarkupCarve\Carve\Node\Block\AbbreviationDefinition;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\LinkReferenceDefinition;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Block\ThematicBreak;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Abbreviation;
use MarkupCarve\Carve\Node\Inline\CitationGroup;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\CriticComment;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\InlineExtension;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\LiteralInline;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Node\Inline\RawInline;
use MarkupCarve\Carve\Node\Inline\RawText;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Strong;
use MarkupCarve\Carve\Node\Inline\Substitution;
use MarkupCarve\Carve\Node\Inline\Symbol;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Inline\UnresolvedReference;
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
        // The MAP cannot carry a term defined twice, and the author wrote both
        // lines, so the authored list rides along beside it. Reading order
        // matters on the way back: the list is what the writer prints, and the
        // map is what resolution uses.
        $abbreviations = $document->getAbbreviations();
        if ($abbreviations !== []) {
            $doc['attrs'] = [
                'carveAbbreviations' => $abbreviations,
                'carveAbbreviationDefinitions' => $document->getAbbreviationDefinitions(),
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

        // The definition child renders nothing here because it is already
        // carried in full: render() puts the definitions, the authored list and
        // the ordering flag on the doc node's attrs, and the converter rebuilds
        // the nodes from them at the position the flag names. Reporting it
        // dropped as well would tell a caller they lost something they did not.
        if ($node instanceof AbbreviationDefinition) {
            return null;
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

        if ($node instanceof CodeBlock || $node instanceof RawBlock || $node instanceof Comment) {
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

        if ($node instanceof Figure && $node->getShortCaption() !== null) {
            // The short caption is state, not a child, so walking children
            // alone loses it - the same shape as a table's caption. It rides as
            // a second carveCaption flagged `short`, which is how the converter
            // tells the two apart on the way back.
            $content[] = [
                'type' => 'carveCaption',
                'attrs' => ['short' => true],
                'content' => $this->renderInlines($node->getShortCaption(), []),
            ];
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
            $rawRef = UnresolvedReference::sourceOf($node);
            if ($rawRef !== null) {
                // The editor model has no unresolved reference: it holds text.
                // Carrying the literal source keeps the characters, but not the
                // fact that they are a reference - the writer escapes them on
                // the way back (`\[a\]\[b\]`), so the document does not come
                // back spelled as it was written. Say so rather than losing it
                // silently (carve-php#519, carve-php#528).
                $this->degraded[$node->getType()] = 'an unresolved reference has no editor counterpart, '
                    . 'so it is carried as its literal source and comes back as escaped text';
                $textNode = ['type' => 'text', 'text' => $rawRef];
                if ($marks !== []) {
                    $textNode['marks'] = $marks;
                }
                $out[] = $textNode;

                continue;
            }

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

            if ($node instanceof Comment) {
                // A comment after paragraph text consumes the line tail. In
                // inline position it is CarveKit's `carveCommentInline` atom,
                // whose text rides in a `content` attr - the block spelling
                // holds its text as a child, which an inline atom cannot.
                $inlineComment = [
                    'type' => 'carveCommentInline',
                    'attrs' => ['content' => $node->getContent()],
                ];
                if ($marks !== []) {
                    $inlineComment['marks'] = $marks;
                }
                $out[] = $inlineComment;

                continue;
            }

            if ($node instanceof Code || $node instanceof CriticComment) {
                // Same asymmetry inline: Carve holds the text on the node, while
                // ProseMirror wants a text node carrying a mark. Both of these
                // have literal content and no children, so descending as a mark
                // would emit an empty element and lose the text.
                // A mark can carry attributes - the generic mark path below
                // already does - so `` `code`{.cls} `` has somewhere to put
                // them after all; omitting them dropped the class outright,
                // which is a meaning loss, not a re-spelling (carve-php#519).
                $ownMark = ['type' => (string)SchemaMap::nameFor($type)];
                $ownAttrs = $this->attributesFor($node);
                if ($ownAttrs !== []) {
                    $ownMark['attrs'] = $ownAttrs;
                }

                $out[] = [
                    'type' => 'text',
                    'text' => $node->getContent(),
                    'marks' => [...$marks, $ownMark],
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
            $node instanceof RawText => $node->getContent(),
            $node instanceof SmartPunctuation => $node->getGlyph(),
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

        // A Mention answers `mention` for both flavors, so the type alone cannot
        // pick between the two names the map lists for it. Without this a `#tag`
        // arrived in the editor as a carveMention and rendered with the mention
        // extension - and carve-grammars, reading the same map, emits carveTag.
        if ($node instanceof Mention && $node->getCssClass() === 'tag') {
            $type = 'tag';
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
        } elseif ($node instanceof ThematicBreak) {
            $attrs['carveMarker'] = $node->char;
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
            // The writer reads exactly three things off a list to decide how to
            // spell its markers - the marker character, the numbering style and
            // the bare-dot flag - and the editor model holds none of them. All
            // three used to be lost, two of them silently, because `1)` and
            // `a.` render the same `<ol>` as `1.` (carve-php#519). Each is only
            // set when it changes what the writer emits, so an ordinary list
            // gains no key that means nothing to it.
            if ($node->getListType() === 'ordered') {
                $attrs['start'] = $node->getStart();
                if ($node->hasBareMarker()) {
                    $attrs['carveBareMarker'] = true;
                }
                // Alphabetic and roman numbering are not a re-spelling: without
                // this, `a. apple` comes back `1. apple` and the visible label
                // changes.
                if ($node->getStyle() !== null) {
                    $attrs['carveListStyle'] = $node->getStyle();
                }
            }
            // Section 11: a different marker character starts a NEW list, so
            // normalizing it can merge two sibling lists into one on re-parse
            // (carve#286). That makes the marker structural, not decoration.
            $marker = $node->getMarker();
            if ($marker !== null && in_array($marker, [')', '*'], true)) {
                $attrs['carveListMarker'] = $marker;
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
            // The cell's OWN marker, not the one it inherits from the column.
            // carve-rs writes `alignment` from `cell.align`, which its parser
            // sets only where the cell carries a marker, so a body cell under
            // an aligned header has no `alignment` there. This engine keeps the
            // inherited value on the node for its HTML writer, so the flag is
            // what separates the two - without it the bridge published an
            // alignment carve-rs does not, and re-read it as a marker the
            // author never wrote.
            if ($node->hasExplicitAlignment() && $node->getAlignment() !== TableCell::ALIGN_DEFAULT) {
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
            // An image takes a reference the way a link does, and its spelling
            // is carried the same way - see the Link branch below. The
            // converter re-confirms against the carveLinkRefDef nodes on the
            // way back.
            if ($node->getReferenceLabel() !== null) {
                $attrs['carveRef'] = $node->getReferenceLabel();
            }
            if ($node->getRawReferenceLabel() !== null) {
                $attrs['carveRawRef'] = $node->getRawReferenceLabel();
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
            // `<https://example.com>` and `[https://example.com](https://...)`
            // are the same mark with the same destination, so the writer had
            // nothing to choose by and always emitted the explicit spelling.
            // The flag is the node's own identity in Carve, not decoration -
            // an autolink is its own type (carve-php#519).
            if ($node->isAutolink()) {
                $attrs['carveAutolink'] = true;
            }
            // The reference SPELLING, for the one reference class the editor
            // model can resolve on its own.
            //
            // `href` alone is what a link renders by, not what it was written
            // as, so a collapsed `[text][]` came back as `[text](#some-id)` -
            // a generated id baked into the source on every pass through the
            // bridge, which is exactly what `fromHeadingReference` exists to
            // prevent in the canonical writer (carve-php#1006).
            //
            // BOTH reference classes carry their spelling now. A heading
            // reference resolves against a `heading` node the bridge carries; a
            // `[text][label]` reference resolves against a
            // `link_reference_definition`, which the bridge carries as
            // `carveLinkRefDef` - so in each case writing the reference back
            // reproduces a working link. The converter re-confirms both against
            // the tree it rebuilt, and a reference whose anchor is gone falls
            // back to its inline form rather than becoming prose.
            if ($node->isFromHeadingReference()) {
                $attrs['carveHeadingRef'] = true;
            }
            if ($node->isFromHeadingReference() || $node->getReferenceLabel() !== null) {
                $referenceLabel = $node->getReferenceLabel();
                if ($referenceLabel !== null) {
                    $attrs['carveRef'] = $referenceLabel;
                }
                $rawReferenceLabel = $node->getRawReferenceLabel();
                if ($rawReferenceLabel !== null) {
                    $attrs['carveRawRef'] = $rawReferenceLabel;
                }
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
        } elseif ($node instanceof RawBlock || $node instanceof RawInline) {
            $attrs['format'] = $node->getFormat();
            if ($node instanceof RawInline) {
                // Inline raw content is an atom: nothing in the editor shows
                // it, so the text rides as an attr rather than a child.
                $attrs['content'] = $node->getContent();
            }
        } elseif ($node instanceof Comment) {
            // CarveKit's shape: the text is the single text child (the
            // codeBlock asymmetry, handled in renderBlock), and `block` is what
            // distinguishes a `%% x` line comment from a fenced one on the way
            // back - the same flag PART 12 publishes as `comment.block`.
            $attrs['block'] = $node->getFenceLength() !== null;
        } elseif ($node instanceof Frontmatter) {
            $attrs['content'] = $node->getContent();
            $attrs['format'] = $node->getFormat();
        } elseif ($node instanceof LinkReferenceDefinition) {
            // The definition is what a `[text][label]` reference resolves
            // against, so carrying it is what lets that reference keep its
            // spelling (see the Link branch above).
            $attrs['label'] = $node->getLabel();
            $attrs['href'] = $node->getHref();
            if ($node->getTitle() !== null) {
                $attrs['title'] = $node->getTitle();
            }
        } elseif ($node instanceof LiteralInline) {
            $attrs['content'] = $node->getContent();
        } elseif ($node instanceof Symbol) {
            $attrs['name'] = $node->getName();
        } elseif ($node instanceof Substitution) {
            $attrs['oldText'] = $node->getOldText();
            $attrs['newText'] = $node->getNewText();
        } elseif ($node instanceof HeadingRef) {
            // The resolved href is a resolution artifact; the target is the
            // authored identity, so only it is carried.
            $attrs['target'] = $node->getTargetId();
        } elseif ($node instanceof CitationGroup) {
            $attrs['raw'] = $node->getRaw();
            $attrs['integral'] = $node->isIntegral();
            // An item's prefix, locator and suffix are inline ARRAYS living
            // outside `children`, so a child walk cannot reach them - the same
            // shape the PART 12 codec handles for the wire. Each rides as a
            // ProseMirror inline array the converter rebuilds with its normal
            // inline path.
            $items = [];
            foreach ($node->getItems() as $item) {
                $encoded = [
                    'key' => $item['key'],
                    'suppressAuthor' => $item['suppressAuthor'],
                ];
                foreach (['prefix', 'locator', 'suffix'] as $inlineField) {
                    if (isset($item[$inlineField])) {
                        $encoded[$inlineField] = $this->renderInlines($item[$inlineField], []);
                    }
                }
                foreach (['locatorLabel', 'locatorValue'] as $stringField) {
                    if (isset($item[$stringField])) {
                        $encoded[$stringField] = $item[$stringField];
                    }
                }
                $items[] = $encoded;
            }
            $attrs['items'] = $items;
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
        if ($node instanceof Span && $node->getChildren() === []) {
            // Same shape as the empty link below, and the same reason: a mark
            // needs text to attach to, so a span with no content has nothing to
            // carry it and the attributes it holds go with it. `x ^[]{.c}`
            // came back as `x ^`, with neither report saying so.
            $this->degraded['span'] = 'a span with no content has no text to carry the mark, '
                . 'so neither it nor its attributes are represented';
        }

        if ($node instanceof Link && !$node instanceof Mention) {
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

        // A list declares nothing. Its style, marker and bare-dot flag all
        // travel as their own keys now, and the grammar admits exactly two
        // bullets and two ordered delimiters, all four of which are carried -
        // `+` is not a bullet in Carve at all, so a guard for "some other
        // marker" would be a branch that cannot run. Reporting a loss that no
        // longer happens is as misleading as the silence this replaced.
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
