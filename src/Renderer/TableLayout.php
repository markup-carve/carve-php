<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableRow;

/**
 * Expands table rows into logical columns for renderers that cannot express
 * rowspan/colspan. Covered columns become empty cells.
 */
final class TableLayout
{
    /**
     * @param \MarkupCarve\Carve\Node\Block\Table $table
     * @param callable $renderCell
     *
     * @return array{rows: array<int, array{isHeader: bool, cells: array<int, mixed|null>, authoredWidth: int}>, columnCount: int}
     */
    public static function expand(Table $table, callable $renderCell): array
    {
        // Every row already has one grid entry per column, including a
        // placeholder for each `^`/`<` span marker (carve-php#527) - so
        // building the flattened layout is a straight walk of TableSpanGrid's
        // resolution: a column a span CLAIMED (`skip`) becomes an empty cell,
        // any other column renders its own cell. There is no longer a need to
        // track "active rowspans" across rows or synthesize colspan fillers
        // separately - the covered columns already have their own (skipped)
        // grid entry to walk into, in place.
        $grid = TableSpanGrid::resolve($table);

        $tableRows = [];
        foreach ($table->getChildren() as $row) {
            if ($row instanceof TableRow) {
                $tableRows[] = $row;
            }
        }

        $rows = [];
        $columnCount = 0;
        foreach ($grid as $index => $gridRow) {
            $cells = [];
            foreach ($gridRow as $entry) {
                $cells[] = $entry['skip'] ? null : $renderCell($entry['cell']);
            }

            $columnCount = max($columnCount, count($cells));
            $rows[] = [
                'isHeader' => $tableRows[$index]->isHeader(),
                'cells' => $cells,
                // How many columns THIS ROW authored, before the padding below.
                // A column a span CLAIMED is authored - the `<` or `^` marker is
                // a cell the writer typed - while padding is a column the row
                // does not reach at all. Both arrive as `null`, so a renderer
                // that trims trailing `null`s cannot tell them apart, and it
                // truncated a row whose LAST column was covered by a span: the
                // text targets wrote `c` where the row spans two columns, and
                // the ANSI target drew a row narrower than its own box border
                // (corpus 256-table-cell-padding-must-be-a-space-21).
                'authoredWidth' => count($cells),
            ];
        }

        foreach ($rows as &$row) {
            $cellCount = count($row['cells']);
            while ($cellCount < $columnCount) {
                $row['cells'][] = null;
                $cellCount++;
            }
        }
        unset($row);

        return [
            'rows' => $rows,
            'columnCount' => $columnCount,
        ];
    }
}
