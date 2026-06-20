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
 */
class ListTableExtension implements ExtensionInterface
{
    /**
     * The div class this extension claims.
     *
     * @var string
     */
    public const KIND = 'list-table';

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
        // block appended to the most recently opened cell.
        $rows = [];
        foreach ($outerList->getChildren() as $rowItem) {
            if (!$rowItem instanceof ListItem) {
                continue;
            }
            $rows[] = $this->extractCells($rowItem);
        }

        if ($rows === []) {
            return null;
        }

        $headerRows = max(0, (int)($node->getAttribute('header-rows') ?? '0'));
        $headerCols = max(0, (int)($node->getAttribute('header-cols') ?? '0'));

        // Resolve `^`/`<` span markers into a grid of placed cells, reusing the
        // SAME continuation model the pipe-table parser uses (see resolveSpans()).
        $grid = $this->resolveSpans($rows);

        // Ragged rows: pad short rows with empty cells to the widest effective
        // row so no content is dropped and the grid stays rectangular. The
        // effective width accounts for colspans and rowspan reservations.
        $columnCount = 0;
        foreach ($grid as $placedRow) {
            $columnCount = max($columnCount, $placedRow['width']);
        }

        $lines = [];

        $title = $node->getAttribute('title');
        if ($title !== null && trim($title) !== '') {
            $lines[] = '  <caption>' . $this->escapeHtml($title) . '</caption>';
        }

        $renderRow = function (array $placedRow, int $rowIndex) use ($renderer, $headerRows, $headerCols, $columnCount): string {
            $isHeaderRow = $rowIndex < $headerRows;
            $html = '';
            // Walk every grid column so trailing columns are padded; columns
            // covered by a span (rowspan from above, or the body of a colspan)
            // are skipped - the spanning cell already covers them.
            for ($col = 0; $col < $columnCount; $col++) {
                $placed = $placedRow['cells'][$col] ?? null;
                // A dropped cell overlaps a rowspan from above; it is kept only
                // for span tracking and emits nothing (its column is covered).
                if ($placed !== null && !empty($placed['dropped'])) {
                    continue;
                }
                if ($placed === null) {
                    if (isset($placedRow['covered'][$col])) {
                        continue;
                    }
                    // A genuinely empty padding column.
                    $tag = ($isHeaderRow || $col < $headerCols) ? 'th' : 'td';
                    $html .= '<' . $tag . '></' . $tag . '>';

                    continue;
                }

                $isHeaderCell = $isHeaderRow || $col < $headerCols;
                $tag = $isHeaderCell ? 'th' : 'td';
                $attrHtml = '';
                if ($placed['rowspan'] > 1) {
                    $attrHtml .= ' rowspan="' . $placed['rowspan'] . '"';
                }
                if ($placed['colspan'] > 1) {
                    $attrHtml .= ' colspan="' . $placed['colspan'] . '"';
                }
                // A `^`/`<` marker with nothing to merge into became an empty
                // cell (pipe-table parity): render no content, not literal `^`.
                $content = empty($placed['empty'])
                    ? $this->renderCell($placed['cell'], $renderer)
                    : '';
                $html .= '<' . $tag . $attrHtml . '>' . $content . '</' . $tag . '>';
            }

            return '<tr>' . $html . '</tr>';
        };

        $headGrid = array_slice($grid, 0, $headerRows);
        $bodyGrid = array_slice($grid, $headerRows);

        if ($headGrid !== []) {
            $thead = '';
            foreach ($headGrid as $rowIndex => $placedRow) {
                $thead .= $renderRow($placedRow, $rowIndex);
            }
            $lines[] = '  <thead>' . $thead . '</thead>';
        }

        if ($bodyGrid !== []) {
            $tbody = '';
            foreach ($bodyGrid as $offset => $placedRow) {
                $tbody .= '    ' . $renderRow($placedRow, $offset + $headerRows) . "\n";
            }
            $lines[] = "  <tbody>\n" . rtrim($tbody, "\n") . "\n  </tbody>";
        }

        $attrs = $this->renderTableAttributes($node, $renderer);

        return '<table' . $attrs . ">\n" . implode("\n", $lines) . "\n</table>\n";
    }

    /**
     * Extract the cells of a row.
     *
     * Carve parses a row like `- - A` / ` - B` / ` - C` as the outer item
     * holding MULTIPLE inner ListBlocks (`list[A]` + `list[B, C]`), so a row's
     * cells are the flattened items of every inner ListBlock child, in document
     * order. Any non-list block sibling (e.g. a trailing paragraph that the
     * parser left outside the inner list) is appended to the most recently
     * opened cell so multi-block content is never dropped.
     *
     * @return array<\Carve\Node\Block\ListItem>
     */
    protected function extractCells(ListItem $rowItem): array
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

            // A stray block following the inner list belongs to the last cell.
            if ($cells !== []) {
                $cells[count($cells) - 1]->appendChild($child);
            }
        }

        return $cells;
    }

    /**
     * Resolve `^` / `<` span markers into a placed grid.
     *
     * This mirrors the pipe-table parser's continuation model so the output
     * matches an equivalent pipe table:
     *
     * - A `<` cell merges into the nearest content cell to its LEFT in the same
     *   row, growing that cell's colspan. Leading `<` (no cell to the left)
     *   becomes an empty cell rather than being dropped.
     * - A `^` cell merges into the cell currently "open" in its column above,
     *   growing that cell's rowspan. A `^` with no cell above (first row, or a
     *   column with no origin) becomes an empty cell.
     * - A cell carrying its own attribute block is never a bare marker; its
     *   `^`/`<` content is literal (the same escape pipe tables use).
     *
     * The result is one entry per input row:
     * `['cells' => array<int, array{cell, rowspan, colspan}>, 'covered' => array<int,bool>, 'width' => int]`
     * keyed by the starting column of each placed cell. `covered` marks columns
     * occupied by a rowspan reaching down from a previous row (so the renderer
     * skips them); `width` is the effective column count of the row.
     *
     * @param array<array<\Carve\Node\Block\ListItem>> $rows
     *
     * @return array<array{cells: array<int, array{cell: \Carve\Node\Block\ListItem, rowspan: int, colspan: int, startCol: int, empty?: bool, dropped?: bool}>, covered: array<int, bool>, width: int}>
     */
    protected function resolveSpans(array $rows): array
    {
        // Per-column origin: for each column, the {rowIndex, col} of the cell
        // currently open in it, so a `^` in a later row can locate and extend
        // that cell in the grid. Populated across a cell's full colspan width.
        $columnOrigin = [];
        // Per-column exclusive row index through which an active rowspan still
        // occupies the column (so later rows skip those columns).
        $occupiedUntil = [];

        $grid = [];
        $maxOverflow = false;
        // True once any rowspan has been created. The overlap scans below only
        // matter when a rowspan can reach down into a row, so a table that is
        // marker-free or colspan-only never pays for them (keeps the common,
        // span-free case O(cells) instead of O(rows^2 * cols)).
        $hasRowspan = false;

        foreach ($rows as $rowIndex => $cells) {
            // First, collapse colspan: a `<` increments the colspan of the most
            // recent content cell; a leading `<` becomes its own empty cell.
            /** @var array<array{cell: \Carve\Node\Block\ListItem, marker: string|null, colspan: int}> $resolvedCells */
            $resolvedCells = [];
            foreach ($cells as $cell) {
                $marker = $this->markerOf($cell);
                // A `<` folds into the entry to its left, growing its colspan -
                // but only into a content cell or a `^` marker (which can itself
                // widen). A LEADING `<` (no foldable entry to the left) becomes
                // its own empty cell, so a run of leading `<` stays separate
                // empty cells rather than merging (pipe-table parity).
                if ($marker === '<' && $resolvedCells !== []) {
                    $lastIndex = count($resolvedCells) - 1;
                    if ($resolvedCells[$lastIndex]['marker'] !== '<') {
                        $last = $resolvedCells[$lastIndex];
                        $last['colspan']++;
                        $resolvedCells[$lastIndex] = $last;

                        continue;
                    }
                }

                $resolvedCells[] = [
                    'cell' => $cell,
                    'marker' => $marker,
                    'colspan' => 1,
                ];
            }

            // PASS 1: place each cell at a running column position. Every item -
            // content cell, `<` (already folded into colspan above) or `^` -
            // advances $col by its OWN colspan; covered columns are NOT skipped.
            // A `^` extends the cell currently open in its column, looked up via
            // $columnOrigin which is populated across a cell's FULL width so a
            // `^` under any column of a wide cell finds it. This mirrors the
            // parser's per-column origin model.
            /** @var array<int, array{cell: \Carve\Node\Block\ListItem, rowspan: int, colspan: int, startCol: int, empty?: bool, dropped?: bool}> $placed */
            $placed = [];
            $col = 0;
            // Origins already extended by a `^` in THIS row, so multiple `^`
            // under one wide cell extend its rowspan only once.
            $extendedThisRow = [];
            // Columns consumed by a `^` marker (its own colspan) that emit no
            // cell of their own; the renderer must skip them, not pad them.
            $markerConsumed = [];
            // Columns held by a rowspan from a PREVIOUS row that reaches into
            // this row. A `^` over such a column is the body of that rowspan, not
            // a new span - it is consumed silently (pipe-table parity). No
            // rowspan yet means nothing can be covered - skip the scan.
            $coveredFromAbove = $hasRowspan
                ? $this->columnsCoveredByPreviousRows($grid, $rowIndex)
                : [];

            foreach ($resolvedCells as $resolved) {
                $colspan = $resolved['colspan'];

                if ($resolved['marker'] === '^') {
                    // A `^` over a column already covered from above belongs to
                    // that rowspan; consume it without emitting a cell.
                    if (isset($coveredFromAbove[$col])) {
                        for ($c = $col; $c < $col + $colspan; $c++) {
                            $markerConsumed[$c] = true;
                        }
                        $col += $colspan;

                        continue;
                    }

                    $origin = $columnOrigin[$col] ?? null;
                    // The origin must still exist: a cell can be dropped in a
                    // later pass (it overlapped a rowspan from above), leaving a
                    // stale origin pointer. Then there is nothing to extend. It
                    // must ALSO be contiguous: a `^` only continues a cell whose
                    // column was occupied in the IMMEDIATELY preceding row. A
                    // ragged row that omitted the column breaks the chain (the
                    // column simply did not exist there), so the `^` starts a
                    // fresh empty cell instead of jumping the gap to an older
                    // cell - matching the pipe table, which has no cell there.
                    $originExists = $origin !== null
                        && $origin['rowIndex'] < $rowIndex
                        && isset($grid[$origin['rowIndex']]['cells'][$origin['col']])
                        && $this->columnOccupiedInRow($grid, $rowIndex - 1, $col);
                    // A cell kept only for tracking after being dropped must NOT
                    // gain a rowspan; a `^` over it is consumed silently so it
                    // does not occupy later columns and skip real cells.
                    if ($originExists && !empty($grid[$origin['rowIndex']]['cells'][$origin['col']]['dropped'])) {
                        for ($c = $col; $c < $col + $colspan; $c++) {
                            $markerConsumed[$c] = true;
                        }
                        $col += $colspan;

                        continue;
                    }
                    if ($originExists) {
                        // Extend the open cell above (only once per origin per
                        // row; a chain of `^` keeps pointing at the same origin).
                        $originCell = &$grid[$origin['rowIndex']]['cells'][$origin['col']];
                        if (!isset($extendedThisRow[spl_object_id($originCell['cell'])])) {
                            $originCell['rowspan']++;
                            $extendedThisRow[spl_object_id($originCell['cell'])] = true;
                            $hasRowspan = true;
                        }
                        $originCol = $origin['col'];
                        $originWidth = $originCell['colspan'];
                        $reachUntil = $origin['rowIndex'] + $originCell['rowspan'];
                        unset($originCell);
                        // The whole origin width stays occupied for this and the
                        // rows the rowspan now reaches.
                        for ($c = $originCol; $c < $originCol + $originWidth; $c++) {
                            $occupiedUntil[$c] = max($occupiedUntil[$c] ?? 0, $reachUntil);
                        }
                        // Columns this marker consumes beyond the origin width
                        // (e.g. a `^` widened by a following `<`) emit no cell;
                        // skip them so the renderer does not pad them.
                        for ($c = $col; $c < $col + $colspan; $c++) {
                            $markerConsumed[$c] = true;
                        }
                        $col += $colspan;

                        continue;
                    }

                    // No cell above to extend: the `^` becomes an empty cell so
                    // content is never silently dropped (pipe-table parity).
                    $placed[$col] = [
                        'cell' => $resolved['cell'],
                        'rowspan' => 1,
                        'colspan' => $colspan,
                        'startCol' => $col,
                        'empty' => true,
                    ];
                    for ($c = $col; $c < $col + $colspan; $c++) {
                        $occupiedUntil[$c] = $rowIndex + 1;
                        $columnOrigin[$c] = ['rowIndex' => $rowIndex, 'col' => $col];
                    }
                    $col += $colspan;

                    continue;
                }

                $placed[$col] = [
                    'cell' => $resolved['cell'],
                    'rowspan' => 1,
                    'colspan' => $colspan,
                    'startCol' => $col,
                    // A leftover leading `<` (no cell to its left to merge into)
                    // is an empty cell, not literal `<` (pipe-table parity).
                    'empty' => $resolved['marker'] === '<',
                ];
                for ($c = $col; $c < $col + $colspan; $c++) {
                    $occupiedUntil[$c] = $rowIndex + 1;
                    $columnOrigin[$c] = ['rowIndex' => $rowIndex, 'col' => $col];
                }
                $col += $colspan;
            }
            $rowWidth = $col;

            // PASS 2: drop placed cells whose start column is covered by a
            // rowspan reaching into THIS row from a previous one (mirrors the
            // parser's removeOverlappingCells()). Recompute occupancy now that
            // pass 1 may have extended a previous row's rowspan into this row.
            $occupiedByPrevious = $hasRowspan
                ? $this->columnsCoveredByPreviousRows($grid, $rowIndex)
                : [];
            $droppedSpan = [];
            foreach ($placed as $startCol => $placedCell) {
                if (isset($occupiedByPrevious[$startCol])) {
                    // The cell overlaps a rowspan from above. Flag it dropped
                    // (the renderer emits nothing) but KEEP it in the grid so a
                    // later `^` in its column can still extend it - mirroring the
                    // parser, where a removed cell's object stays live for
                    // rowspan tracking. Cover its full width so it is not padded.
                    $placed[$startCol]['dropped'] = true;
                    for ($c = $startCol; $c < $startCol + $placedCell['colspan']; $c++) {
                        $droppedSpan[$c] = true;
                    }
                }
            }

            // Mark every grid column the renderer must skip: the body columns of
            // a colspan, any column held by a rowspan from a previous row, every
            // column a dropped overlapping cell occupied, and `^`-consumed ones.
            $covered = [];
            foreach ($placed as $startCol => $placedCell) {
                for ($c = $startCol + 1; $c < $startCol + $placedCell['colspan']; $c++) {
                    $covered[$c] = true;
                }
            }
            foreach (array_keys($droppedSpan) as $c) {
                $covered[$c] = true;
            }
            foreach (array_keys($occupiedByPrevious) as $c) {
                $covered[$c] = true;
            }
            foreach (array_keys($markerConsumed) as $c) {
                $covered[$c] = true;
            }

            $grid[$rowIndex] = [
                'cells' => $placed,
                'covered' => $covered,
                'width' => $rowWidth,
            ];
        }

        // Detect overflow: rowspans reaching past the last row would point at a
        // row that does not exist. Warn rather than silently corrupt.
        foreach ($occupiedUntil as $end) {
            if ($end > count($rows)) {
                $maxOverflow = true;

                break;
            }
        }
        if ($maxOverflow) {
            trigger_error(
                'list-table: a rowspan marker extends past the last row; the spanning cell is clamped to the table.',
                E_USER_WARNING,
            );
        }

        return $grid;
    }

    /**
     * Columns covered, in the row at `$currentRowIndex`, by a rowspan that
     * started in an EARLIER row and reaches into it.
     *
     * Mirrors the parser's removeOverlappingCells() occupancy scan: it walks the
     * already-built rows and, per column, records the exclusive row index through
     * which an active rowspan occupies it. A column is "covered" for the current
     * row when that index is greater than the current row index.
     *
     * @param array<array{cells: array<int, array{cell: \Carve\Node\Block\ListItem, rowspan: int, colspan: int, startCol: int, empty?: bool, dropped?: bool}>, covered: array<int, bool>, width: int}> $grid
     * @param int $currentRowIndex
     *
     * @return array<int, bool>
     */
    protected function columnsCoveredByPreviousRows(array $grid, int $currentRowIndex): array
    {
        $occupiedUntil = [];
        foreach ($grid as $rowIndex => $placedRow) {
            if ($rowIndex >= $currentRowIndex) {
                break;
            }
            foreach ($placedRow['cells'] as $startCol => $placedCell) {
                $end = $rowIndex + $placedCell['rowspan'];
                for ($c = $startCol; $c < $startCol + $placedCell['colspan']; $c++) {
                    $occupiedUntil[$c] = max($occupiedUntil[$c] ?? 0, $end);
                }
            }
        }

        $covered = [];
        foreach ($occupiedUntil as $col => $end) {
            if ($end > $currentRowIndex) {
                $covered[$col] = true;
            }
        }

        return $covered;
    }

    /**
     * Whether `$col` was occupied in the already-built row at `$rowIndex`.
     *
     * A column counts as occupied if that row placed a cell whose colspan covers
     * it, or it is in that row's `covered` set (a rowspan reaching through, or a
     * span body). Used to gate `^` continuation: a ragged row that omits a column
     * breaks the rowspan chain, so a `^` below it starts a fresh empty cell.
     *
     * @param array<array{cells: array<int, array{cell: \Carve\Node\Block\ListItem, rowspan: int, colspan: int, startCol: int, empty?: bool, dropped?: bool}>, covered: array<int, bool>, width: int}> $grid
     * @param int $rowIndex
     * @param int $col
     *
     * @return bool
     */
    protected function columnOccupiedInRow(array $grid, int $rowIndex, int $col): bool
    {
        if ($rowIndex < 0 || !isset($grid[$rowIndex])) {
            return false;
        }

        if (isset($grid[$rowIndex]['covered'][$col])) {
            return true;
        }

        foreach ($grid[$rowIndex]['cells'] as $startCol => $placedCell) {
            if ($col >= $startCol && $col < $startCol + $placedCell['colspan']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect a span marker cell.
     *
     * Returns `'^'` or `'<'` when the cell's sole inline content is exactly that
     * marker character, or null otherwise. A cell carrying its own attribute
     * block is never a marker (the `^`/`<` is then literal), matching the escape
     * rule pipe tables use.
     */
    protected function markerOf(ListItem $cell): ?string
    {
        if ($cell->getAttributes() !== []) {
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
     */
    protected function renderCell(ListItem $cell, HtmlRenderer $renderer): string
    {
        $children = $cell->getChildren();

        if (count($children) === 1 && $children[0] instanceof Paragraph && $children[0]->getAttributes() === []) {
            $html = rtrim($renderer->renderNodeFragment($children[0]), "\n");

            // Strip the single <p>…</p> wrapper to inline the content.
            if (preg_match('/^<p>(.*)<\/p>$/s', $html, $m) === 1) {
                return $m[1];
            }

            return $html;
        }

        $html = '';
        foreach ($children as $child) {
            $html .= $renderer->renderNodeFragment($child);
        }

        return rtrim($html, "\n");
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

        $html = '';
        foreach ($attrs as $key => $value) {
            $html .= ' ' . $this->escapeHtml((string)$key) . '="' . $renderer->escapeAttribute((string)$value) . '"';
        }

        return $html;
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

        return str_replace("\u{E000}", '&nbsp;', $escaped);
    }
}
