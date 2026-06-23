<?php

declare(strict_types=1);

namespace Carve\Extension;

use Carve\CarveConverter;
use Carve\Event\RenderEvent;
use Carve\Node\Block\Div;
use Carve\Node\Block\ListBlock;
use Carve\Node\Block\ListItem;
use Carve\Node\Block\Paragraph;
use Carve\Node\Inline\Text;
use Carve\Renderer\HtmlRenderer;

/**
 * Renders `::: list-table` blocks as real HTML `<table>` markup, with the
 * table authored as a nested list so that cells can hold full block content
 * (paragraphs, lists, code, …) that the native pipe-table syntax cannot.
 *
 * A `list-table` div is authored as an outer list where each outer item is a
 * row and each inner item is a cell:
 *
 * ```
 * {header-rows=1}
 * ::: list-table "Quarterly results"
 * - - Region
 *   - Notes
 * - - EMEA
 *   - Strong quarter.
 *
 *     Drivers:
 *
 *     - new logos
 *     - renewals
 * :::
 * ```
 *
 * Note the attributes (`{header-rows=1}`) sit on the PRECEDING line: a trailing
 * `{...}` on the `:::` opener would make the whole block literal in Carve.
 *
 * The caption comes from the quoted title; `header-rows=N` promotes the first N
 * rows to `<thead>`/`<th>`, and `header-cols=N` promotes the first N cells of
 * every row to row-header `<th>`. Single-paragraph cells collapse to inline
 * content (`<td>text</td>`), matching tight list-item rendering; multi-block
 * cells keep their block wrappers.
 *
 * Cells may span rows and columns using the SAME continuation markers Carve's
 * native pipe tables use: a cell whose sole inline content is a lone `^` merges
 * with the cell ABOVE (rowspan), and a lone `<` merges with the cell to the LEFT
 * (colspan). The output `<table>` matches what the equivalent pipe table would
 * produce. A cell carrying its own attribute block is never a span marker - its
 * `^`/`<` content is then literal (the same escape pipe tables use):
 *
 * ```
 * {header-rows=1}
 * ::: list-table "Sales"
 * - - Region
 *   - Q1
 *   - Q2
 * - - EMEA
 *   - 10
 *   - 12
 * - - ^
 *   - 14
 *   - 16
 * - - Total
 *   - <
 *   - <
 * :::
 * ```
 *
 * EMEA's cell gets `rowspan="2"` (it plus the `^` below); "Total" gets
 * `colspan="3"` (it plus the two `<`).
 *
 * Only `::: list-table` divs are claimed; every other div defers to the core
 * renderer. When this extension is not registered the block degrades to the
 * default `<div class="list-table">` holding the literal nested list.
 *
 * Only applies to HTML output.
 *
 * Example:
 * ```php
 * $converter = new CarveConverter();
 * $converter->addExtension(new ListTableExtension());
 * ```
 *
 * @phpstan-type GridEntry array{cell: \Carve\Node\Block\ListItem, marker: string|null, rowspan: int, colspan: int, skip: bool}
 */
class ListTableExtension implements ExtensionInterface
{
    /**
     * The div class this extension claims.
     *
     * @var string
     */
    public const KIND = 'list-table';

    /**
     * DoS guards: span resolution is ~O(rows^2), so cap the dimensions and defer
     * anything larger to the plain nested-list div. Far beyond any legitimate
     * hand-authored table; kept identical across carve-php / carve-js / carve-rs.
     *
     * @var int
     */
    public const MAX_ROWS = 10000;

    /**
     * @var int
     */
    public const MAX_CELLS = 100000;

    public function register(CarveConverter $converter): void
    {
        $renderer = $converter->getRenderer();
        if (!$renderer instanceof HtmlRenderer) {
            return;
        }

        $converter->on('render.div', function (RenderEvent $event) use ($renderer): void {
            $node = $event->getNode();
            if (!$node instanceof Div) {
                return;
            }

            // Only claim `::: list-table` blocks; everything else defers to the
            // core div renderer (and any other extension that wants it).
            if (!$node->hasClass(self::KIND)) {
                return;
            }

            $html = $this->renderListTable($node, $renderer);
            if ($html === null) {
                // No usable outer list found; defer to the default div renderer
                // so content is never silently dropped.
                return;
            }

            $event->setHtml($html);
        });
    }

    /**
     * Render the `<table>` for a `list-table` div, or null to defer.
     */
    protected function renderListTable(Div $node, HtmlRenderer $renderer): ?string
    {
        // Claim the div only when its sole block child is the table list. If it
        // holds extra siblings (a stray paragraph before/after the list, etc.)
        // defer to the default div renderer so that content is never silently
        // dropped - the block then degrades to the literal nested-list div.
        $children = $node->getChildren();
        if (count($children) !== 1 || !$children[0] instanceof ListBlock) {
            return null;
        }
        $outerList = $children[0];

        // Each outer list item is a row; its cells are the items of all inner
        // ListBlock children, in document order, with any trailing non-list
        // block recorded against the most recently opened cell.
        //
        // Cell extraction is NON-MUTATING: trailing stray blocks are collected
        // into $extras (keyed by the cell's object id) rather than appended onto
        // the cell node. This is deliberate - the defer decision below must be
        // made on a pristine AST, so that a deferred render (e.g. a later row
        // with no cells) leaves the tree exactly as the parser produced it and
        // the default div renderer cannot duplicate content.
        $rows = [];
        $extras = [];
        foreach ($outerList->getChildren() as $rowItem) {
            if (!$rowItem instanceof ListItem) {
                continue;
            }
            $rows[] = $this->extractCells($rowItem, $extras);
        }

        if ($rows === []) {
            return null;
        }

        // A row that yielded zero cells (e.g. a row authored as a plain
        // paragraph, `- not-a-cell-row`, with no inner cell list) cannot be
        // rendered as table cells without dropping its content. Defer the whole
        // div to the default renderer so the literal nested list is emitted and
        // nothing is lost. This mirrors the sibling djot-php extension's guard.
        $totalCells = 0;
        foreach ($rows as $cells) {
            if ($cells === []) {
                return null;
            }
            $totalCells += count($cells);
        }

        // DoS guard: span resolution rescans prior rows (~O(rows^2)). Cap the
        // dimensions and defer an over-large table to the plain div (content
        // preserved, no quadratic blow-up). Limits match carve-js / carve-rs.
        if (count($rows) > self::MAX_ROWS || $totalCells > self::MAX_CELLS) {
            return null;
        }

        $headerRows = $this->headerCount($node->getAttribute('header-rows'));
        $headerCols = $this->headerCount($node->getAttribute('header-cols'));

        // Resolve `^`/`<` span markers into a positional grid (one entry per
        // SOURCE cell), EXACTLY mirroring the pipe-table span model so the output
        // matches the equivalent pipe table. A `^` grows the rowspan of the
        // nearest non-skipped cell above it in the same source column; a `<` grows
        // the colspan of the nearest non-skipped cell to its left (scanning past
        // already-merged columns). A merged marker is flagged `skip` and emits
        // nothing; an unmergeable marker stays a rendered-empty cell. The
        // header-row count clamps rowspans at the header/body boundary: an HTML
        // cell cannot reliably span across <thead>/<tbody>, so a `^` that would
        // extend a header cell down into the body is not merged and degrades to an
        // empty cell instead.
        $grid = $this->resolveSpans($rows, $headerRows, $extras);

        // Flow each non-skipped cell to an output column, past any column a
        // rowspan from an earlier row still holds - the same flow a browser uses.
        // Unlike a pipe table, a list-table pads every row to the widest row's
        // reach so the grid stays rectangular (carve-js list-table parity); a
        // rowspan from above that already covers a trailing column suppresses the
        // padding there.
        $placement = $this->placeColumns($grid);
        $columnCount = $placement['columnCount'];

        $lines = [];

        $title = $node->getAttribute('title');
        if ($title !== null && trim($title) !== '') {
            $lines[] = '  <caption>' . $this->escapeHtml($title) . '</caption>';
        }

        $renderRow = function (array $gridRow, int $rowIndex) use ($renderer, $headerRows, $headerCols, $columnCount, $extras, $placement): string {
            $isHeaderRow = $rowIndex < $headerRows;
            $cols = $placement['cols'][$rowIndex];
            $html = '';
            $nextCol = 0;
            foreach ($gridRow as $i => $entry) {
                // A merged `^`/`<` emits nothing - its column was absorbed by the
                // cell it merged into (a rowspan above, or the cell to its left).
                if ($entry['skip']) {
                    continue;
                }

                $col = $cols[$i];
                $isHeaderCell = $isHeaderRow || $col < $headerCols;
                $tag = $isHeaderCell ? 'th' : 'td';
                $attrHtml = '';
                if ($entry['rowspan'] > 1) {
                    $attrHtml .= ' rowspan="' . $entry['rowspan'] . '"';
                }
                if ($entry['colspan'] > 1) {
                    $attrHtml .= ' colspan="' . $entry['colspan'] . '"';
                }
                // Carry the cell's own list-item attributes (e.g. `{.x}`) onto
                // the <td>/<th> so authored cell styling is not dropped. The
                // structural span attributes above always win on conflict.
                $attrHtml .= $this->renderCellAttributes($entry['cell'], $renderer);
                // A `^`/`<` marker (merged or not) renders no content, not literal
                // `^` (pipe-table parity).
                $content = $entry['marker'] !== null
                    ? ''
                    : $this->renderCell($entry['cell'], $renderer, $extras[spl_object_id($entry['cell'])] ?? []);
                $html .= '<' . $tag . $attrHtml . '>' . $content . '</' . $tag . '>';
                $nextCol = $col + $entry['colspan'];
            }

            // Pad trailing columns so a ragged row stays rectangular. A rowspan
            // from above that reaches the end of the row already covers those
            // columns, so it suppresses padding (rowReach accounts for it).
            $col = max($nextCol, $placement['rowReach'][$rowIndex]);
            for (; $col < $columnCount; $col++) {
                $tag = ($isHeaderRow || $col < $headerCols) ? 'th' : 'td';
                $html .= '<' . $tag . '></' . $tag . '>';
            }

            return '<tr>' . $html . '</tr>';
        };

        $headGrid = array_slice($grid, 0, $headerRows);
        $bodyGrid = array_slice($grid, $headerRows);

        if ($headGrid !== []) {
            $thead = '';
            foreach ($headGrid as $rowIndex => $gridRow) {
                $thead .= $renderRow($gridRow, $rowIndex);
            }
            $lines[] = '  <thead>' . $thead . '</thead>';
        }

        if ($bodyGrid !== []) {
            $tbody = '';
            foreach ($bodyGrid as $offset => $gridRow) {
                $tbody .= '    ' . $renderRow($gridRow, $offset + $headerRows) . "\n";
            }
            $lines[] = "  <tbody>\n" . rtrim($tbody, "\n") . "\n  </tbody>";
        }

        $attrs = $this->renderTableAttributes($node, $renderer);

        return '<table' . $attrs . ">\n" . implode("\n", $lines) . "\n</table>\n";
    }

    /**
     * Extract the cells of a row WITHOUT mutating the AST.
     *
     * Carve parses a row like `- - A` / ` - B` / ` - C` as the outer item
     * holding MULTIPLE inner ListBlocks (`list[A]` + `list[B, C]`), so a row's
     * cells are the flattened items of every inner ListBlock child, in document
     * order. Any non-list block sibling (e.g. a trailing paragraph that the
     * parser left outside the inner list) belongs to the most recently opened
     * cell so multi-block content is never dropped.
     *
     * Those stray blocks are recorded in $extras (keyed by the cell's object id)
     * rather than appended onto the cell node, so the source tree stays pristine
     * for the defer decision (see renderListTable). renderCell() reads them back.
     *
     * @param \Carve\Node\Block\ListItem $rowItem
     * @param array<int, array<\Carve\Node\Node>> $extras Receives, per cell
     *   object id, the trailing blocks that belong to that cell.
     *
     * @return array<\Carve\Node\Block\ListItem>
     */
    protected function extractCells(ListItem $rowItem, array &$extras): array
    {
        $cells = [];
        foreach ($rowItem->getChildren() as $child) {
            if ($child instanceof ListBlock) {
                foreach ($child->getChildren() as $cellItem) {
                    if ($cellItem instanceof ListItem) {
                        $cells[] = $cellItem;
                    }
                }

                continue;
            }

            // A stray block following the inner list belongs to the last cell;
            // record it against that cell without touching the node tree.
            if ($cells !== []) {
                $extras[spl_object_id($cells[count($cells) - 1])][] = $child;
            }
        }

        return $cells;
    }

    /**
     * Resolve `^` / `<` span markers into a positional grid, EXACTLY mirroring
     * the pipe-table span model (BlockParser grid walk / carve-js render-html
     * `renderTable`) so the output is identical to the equivalent pipe table.
     *
     * Each row becomes a positional list of grid entries (one per SOURCE cell;
     * ragged rows simply have fewer columns). A single left-to-right walk then:
     *
     * - a `^` cell grows the rowspan of the nearest non-skipped cell directly
     *   above it in the same source column, then is flagged `skip`. The origin is
     *   tracked per column via `$lastNonSkip`, so an all-`^` column resolves in
     *   O(1).
     * - a `<` cell grows the colspan of the nearest non-skipped cell to its LEFT,
     *   scanning PAST columns already merged by another span, then is flagged
     *   `skip`. A leading `<` (or one whose leftward scan runs off the table edge)
     *   finds no source: it stays an unmerged marker and renders as an empty cell.
     * - a marker that cannot merge (first-row `^`, leading/blocked `<`, or a `^`
     *   clamped at the header/body boundary) stays non-skipped; its content is
     *   still suppressed at render time (an empty `<td>`/`<th>`).
     * - a cell carrying its own attribute block, or one that owns trailing blocks,
     *   is never a bare marker (its `^`/`<` is literal); see markerOf().
     *
     * `headerRows` clamps a rowspan so it never crosses the header/body boundary:
     * a `^` in a body row whose origin sits in the header rows is NOT merged and
     * degrades to an empty cell (an HTML cell cannot span row groups reliably).
     *
     * Every grid entry is `{cell, marker, rowspan, colspan, skip}`; output columns
     * are assigned separately by placeColumns().
     *
     * @param array<array<\Carve\Node\Block\ListItem>> $rows
     * @param int $headerRows Number of leading rows that form the `<thead>`.
     * @param array<int, array<\Carve\Node\Node>> $extras Per-cell trailing blocks
     *   (keyed by cell object id). A cell that owns trailing blocks is multi-block
     *   and is therefore never a bare span marker, even if its first paragraph is
     *   a lone `^`/`<` - the extra block keeps it a real content cell.
     *
     * @return array<array<int, GridEntry>>
     */
    protected function resolveSpans(array $rows, int $headerRows = 0, array $extras = []): array
    {
        // Build the positional grid: one entry per source cell, in source order.
        /** @var array<array<int, GridEntry>> $grid */
        $grid = [];
        foreach ($rows as $cells) {
            /** @var array<int, GridEntry> $gridRow */
            $gridRow = [];
            foreach ($cells as $cell) {
                $gridRow[] = [
                    'cell' => $cell,
                    'marker' => $this->markerOf($cell, $extras),
                    'rowspan' => 1,
                    'colspan' => 1,
                    'skip' => false,
                ];
            }
            $grid[] = $gridRow;
        }

        // Per source column, the last row index (above the current one) whose
        // cell is not skipped - the nearest source a `^` can extend.
        $lastNonSkip = [];
        $rowCount = count($grid);
        for ($r = 0; $r < $rowCount; $r++) {
            $colCount = count($grid[$r]);
            for ($c = 0; $c < $colCount; $c++) {
                $entry = $grid[$r][$c];
                if ($entry['skip']) {
                    continue;
                }

                if ($entry['marker'] === '^' && $r > 0) {
                    $up = $lastNonSkip[$c] ?? null;
                    // Clamp at the header/body boundary: a `^` in a body row must
                    // not extend a cell that originated in the header rows. Leave
                    // it unmerged (renders as an empty cell) so no <th rowspan>
                    // crosses into <tbody>.
                    $crossesHeader = $up !== null && $up < $headerRows && $r >= $headerRows;
                    if ($up !== null && isset($grid[$up][$c]) && !$crossesHeader) {
                        $grid[$up][$c]['rowspan'] = $grid[$up][$c]['rowspan'] + 1;
                        $grid[$r][$c]['skip'] = true;
                    }
                } elseif ($entry['marker'] === '<' && $c > 0) {
                    $left = $c - 1;
                    while ($left >= 0 && $grid[$r][$left]['skip']) {
                        $left--;
                    }
                    if ($left >= 0) {
                        $grid[$r][$left]['colspan'] = $grid[$r][$left]['colspan'] + 1;
                        $grid[$r][$c]['skip'] = true;
                    }
                }

                // A cell that ends up non-skipped becomes the nearest source for
                // the cells below it in this column.
                if (!$grid[$r][$c]['skip']) {
                    $lastNonSkip[$c] = $r;
                }
            }
        }

        // Warn (rather than silently corrupt) when a rowspan reaches past the
        // last row - a `^` chain longer than the rows beneath its origin. The
        // spanning cell is then clamped to the table by the browser, but the
        // author likely has a typo, so surface it (pipe-table / carve-js parity).
        // The in-place span mutations above leave PHPStan unable to prove the
        // entry shape is still sealed, so re-assert it (no key is ever unset).
        /** @var array<array<int, GridEntry>> $resolved */
        $resolved = $grid;
        foreach ($resolved as $rowIndex => $gridRow) {
            foreach ($gridRow as $entry) {
                if ($entry['rowspan'] > 1 && $rowIndex + $entry['rowspan'] > $rowCount) {
                    trigger_error(
                        'list-table: a rowspan marker extends past the last row; the spanning cell is clamped to the table.',
                        E_USER_WARNING,
                    );

                    break 2;
                }
            }
        }

        return $resolved;
    }

    /**
     * Assign each rendered cell an output column by flowing it top-down past any
     * column a rowspan from an earlier row still holds - the same flow a browser
     * (and the pipe table) uses. Skip cells (merged markers) take no column.
     *
     * @param array<array<int, GridEntry>> $grid
     *
     * @return array{cols: array<array<int, int>>, rowReach: array<int, int>, columnCount: int}
     */
    protected function placeColumns(array $grid): array
    {
        // occupiedUntil[col] = exclusive row index through which a rowspan holds.
        $occupiedUntil = [];
        $cols = [];
        $rowReach = [];
        $columnCount = 0;

        $rowCount = count($grid);
        for ($r = 0; $r < $rowCount; $r++) {
            $rowCols = [];
            $col = 0;
            $reach = 0;
            // A rowspan descending from above into this row reaches at least its
            // column, so the row stays as wide as that coverage.
            foreach ($occupiedUntil as $heldCol => $end) {
                if ($end > $r) {
                    $reach = max($reach, $heldCol + 1);
                }
            }

            foreach ($grid[$r] as $entry) {
                if ($entry['skip']) {
                    $rowCols[] = -1;

                    continue;
                }
                // Flow past columns a rowspan from above still holds in this row.
                while (($occupiedUntil[$col] ?? 0) > $r) {
                    $col++;
                }
                $rowCols[] = $col;
                if ($entry['rowspan'] > 1) {
                    for ($c = $col; $c < $col + $entry['colspan']; $c++) {
                        $occupiedUntil[$c] = max($occupiedUntil[$c] ?? 0, $r + $entry['rowspan']);
                    }
                }
                $col += $entry['colspan'];
                $reach = max($reach, $col);
            }

            $cols[] = $rowCols;
            $rowReach[] = $reach;
            $columnCount = max($columnCount, $reach);
        }

        return ['cols' => $cols, 'rowReach' => $rowReach, 'columnCount' => $columnCount];
    }

    /**
     * Detect a span marker cell.
     *
     * Returns `'^'` or `'<'` when the cell's sole inline content is exactly that
     * marker character, or null otherwise. A cell carrying its own attribute
     * block is never a marker (the `^`/`<` is then literal), matching the escape
     * rule pipe tables use. A cell that owns trailing blocks (recorded in
     * $extras) is multi-block content, so it is never a bare marker either - the
     * extra block keeps it a real cell whose `^`/`<` first line stays literal.
     *
     * @param \Carve\Node\Block\ListItem $cell
     * @param array<int, array<\Carve\Node\Node>> $extras Per-cell trailing blocks.
     */
    protected function markerOf(ListItem $cell, array $extras = []): ?string
    {
        if ($cell->getAttributes() !== []) {
            return null;
        }

        // Trailing blocks make this a multi-block cell, not a bare marker.
        if (($extras[spl_object_id($cell)] ?? []) !== []) {
            return null;
        }

        $children = $cell->getChildren();
        if (count($children) !== 1) {
            return null;
        }

        $paragraph = $children[0];
        if (!$paragraph instanceof Paragraph || $paragraph->getAttributes() !== []) {
            return null;
        }

        $inlines = $paragraph->getChildren();
        if (count($inlines) !== 1) {
            return null;
        }

        $text = $inlines[0];
        if (!$text instanceof Text || $text->getAttributes() !== []) {
            return null;
        }

        $content = trim($text->getContent());
        if ($content === '^' || $content === '<') {
            return $content;
        }

        return null;
    }

    /**
     * Render a single cell's content.
     *
     * A cell whose only child is an attribute-free paragraph collapses to its
     * inline content (no `<p>` wrapper), matching tight list-item/table-cell
     * rendering. Otherwise the block children render normally and keep their
     * wrappers.
     *
     * Trailing stray blocks (collected non-mutatingly in $extras by
     * extractCells) render after the cell's own children, so multi-block content
     * the parser left outside the inner list is preserved without ever having
     * mutated the source tree.
     *
     * @param \Carve\Node\Block\ListItem $cell
     * @param \Carve\Renderer\HtmlRenderer $renderer
     * @param array<\Carve\Node\Node> $extras Trailing blocks belonging to this cell.
     */
    protected function renderCell(ListItem $cell, HtmlRenderer $renderer, array $extras = []): string
    {
        $children = $cell->getChildren();
        $blocks = array_merge($children, $extras);

        if (count($blocks) === 1 && $blocks[0] instanceof Paragraph && $blocks[0]->getAttributes() === []) {
            $html = rtrim($renderer->renderNodeFragment($blocks[0]), "\n");

            // Strip the single <p>…</p> wrapper to inline the content.
            if (preg_match('/^<p>(.*)<\/p>$/s', $html, $m) === 1) {
                return $m[1];
            }

            return $html;
        }

        $html = '';
        foreach ($blocks as $child) {
            $html .= $renderer->renderNodeFragment($child);
        }

        return rtrim($html, "\n");
    }

    /**
     * Build a cell's own attribute markup for its `<td>`/`<th>` tag.
     *
     * Carries a cell list-item's authored attributes (id, classes, key=value)
     * onto the rendered cell tag, so cell-level styling is not silently dropped.
     * The structural span attributes (`rowspan`/`colspan`) are emitted by the
     * caller and take precedence; any `rowspan`/`colspan` the author wrote on the
     * cell itself is ignored here to avoid a duplicate attribute. Safe-mode
     * filtering matches the core renderer.
     */
    protected function renderCellAttributes(ListItem $cell, HtmlRenderer $renderer): string
    {
        $attrs = $cell->getAttributes();
        if ($attrs === []) {
            return '';
        }

        // Drop any author-written span attribute case-insensitively (HTML
        // attribute names are case-insensitive). The structural rowspan/colspan
        // the caller emits must be the only one, so `{RowSpan=9}` cannot produce
        // a duplicate, ambiguous attribute alongside the computed one.
        foreach (array_keys($attrs) as $key) {
            $lower = strtolower((string)$key);
            if ($lower === 'rowspan' || $lower === 'colspan') {
                unset($attrs[$key]);
            }
        }

        // Always-on attribute hardening (matches the core renderer), plus any
        // additional safe-mode name filtering.
        $attrs = $renderer->sanitizeAttributes($attrs);
        $safeMode = $renderer->getSafeMode();
        if ($safeMode !== null) {
            $attrs = $safeMode->filterAttributes($attrs);
        }

        return $renderer->renderAttributeArray($attrs);
    }

    /**
     * Build the `<table>` tag attributes.
     *
     * Drops the structural attributes consumed by this extension (`title`,
     * `header-rows`, `header-cols`) and the auto `list-table` class (the
     * `<table>` tag is itself the styling hook); preserves any sibling classes
     * and other attributes in source order. Applies the same safe-mode
     * filtering the core renderer does.
     */
    protected function renderTableAttributes(Div $node, HtmlRenderer $renderer): string
    {
        $attrs = $node->getAttributes();
        unset($attrs['title'], $attrs['header-rows'], $attrs['header-cols']);

        $attrs = $renderer->sanitizeAttributes($attrs);
        $safeMode = $renderer->getSafeMode();
        if ($safeMode !== null) {
            $attrs = $safeMode->filterAttributes($attrs);
        }

        if (isset($attrs['class'])) {
            $classes = array_values(array_filter(
                preg_split('/\s+/', trim($attrs['class'])) ?: [],
                static fn (string $class): bool => $class !== '' && $class !== self::KIND,
            ));

            if ($classes === []) {
                unset($attrs['class']);
            } else {
                $attrs['class'] = implode(' ', $classes);
            }
        }

        return $renderer->renderAttributeArray($attrs);
    }

    /**
     * Resolve a `header-rows` / `header-cols` attribute to a count.
     *
     * - absent (`null`) -> 0 (no header rows/cols)
     * - present but empty (the boolean form `{header-rows}`, which Carve stores
     *   as `header-rows=""`) -> 1, i.e. the first row/column is the header - the
     *   default behavior most tables want, so `{header-rows}` alone suffices
     * - an explicit number (`{header-rows=2}`) -> that count (clamped at 0)
     */
    protected function headerCount(?string $value): int
    {
        if ($value === null) {
            return 0;
        }
        if (trim($value) === '') {
            return 1;
        }

        return max(0, (int)$value);
    }

    /**
     * Escape text for HTML content (caption / attribute names).
     *
     * Matches the core renderer's `escape()`: escapes only `<`, `>`, `&`
     * (ENT_NOQUOTES, djot keeps quotes literal) and converts the escaped-space
     * placeholder to `&nbsp;`.
     */
    protected function escapeHtml(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');

        return str_replace(["\u{E000}", "\u{00A0}"], '&nbsp;', $escaped);
    }
}
