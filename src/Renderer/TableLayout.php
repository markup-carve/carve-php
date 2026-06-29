<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
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
     * @return array{rows: array<int, array{isHeader: bool, cells: array<int, mixed|null>}>, columnCount: int}
     */
    public static function expand(Table $table, callable $renderCell): array
    {
        $rows = [];
        $activeRowspans = [];
        $columnCount = 0;

        foreach ($table->getChildren() as $row) {
            if (!$row instanceof TableRow) {
                continue;
            }

            $cells = [];
            $column = 0;

            foreach ($row->getChildren() as $cell) {
                if (!$cell instanceof TableCell) {
                    continue;
                }

                self::appendActiveRowspans($cells, $activeRowspans, $column);

                $colspan = max(1, $cell->getColspan());
                $rowspan = max(1, $cell->getRowspan());
                $cells[] = $renderCell($cell);
                for ($offset = 1; $offset < $colspan; $offset++) {
                    $cells[] = null;
                }

                if ($rowspan > 1) {
                    for ($offset = 0; $offset < $colspan; $offset++) {
                        $activeRowspans[$column + $offset] = $rowspan - 1;
                    }
                }

                $column += $colspan;
            }

            self::appendTrailingActiveRowspans($cells, $activeRowspans, $column);

            $columnCount = max($columnCount, count($cells));
            $rows[] = [
                'isHeader' => $row->isHeader(),
                'cells' => $cells,
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

    /**
     * @param array<int, mixed> $cells
     * @param array<int, int> $activeRowspans
     * @param int $column
     */
    private static function appendActiveRowspans(array &$cells, array &$activeRowspans, int &$column): void
    {
        while (isset($activeRowspans[$column])) {
            $cells[] = null;
            $activeRowspans[$column]--;
            if ($activeRowspans[$column] <= 0) {
                unset($activeRowspans[$column]);
            }
            $column++;
        }
    }

    /**
     * @param array<int, mixed> $cells
     * @param array<int, int> $activeRowspans
     * @param int $column
     */
    private static function appendTrailingActiveRowspans(array &$cells, array &$activeRowspans, int &$column): void
    {
        while ($activeRowspans !== [] && $column <= max(array_keys($activeRowspans))) {
            if (isset($activeRowspans[$column])) {
                self::appendActiveRowspans($cells, $activeRowspans, $column);
            } else {
                $cells[] = null;
                $column++;
            }
        }
    }
}
