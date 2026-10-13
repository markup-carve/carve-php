<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;

/**
 * Resolves a table's `^`/`<` placeholder cells into a rendering grid.
 *
 * The T5 walk (PART 9, carve#3047) lives here once: the pipe-table parser, the
 * HTML renderer and the list-table extension all resolve markers through
 * walkRow(), so a span never covers a position another cell owns.
 */
final class TableSpanGrid
{
    /**
     * @return list<list<array{cell: \MarkupCarve\Carve\Node\Block\TableCell, rowspan: int, colspan: int, skip: bool}>>
     */
    public static function resolve(Table $table): array
    {
        $cells = [];
        $markers = [];
        foreach ($table->getChildren() as $row) {
            if (!$row instanceof TableRow) {
                continue;
            }
            $rowCells = [];
            $rowMarkers = [];
            foreach ($row->getChildren() as $cell) {
                if ($cell instanceof TableCell) {
                    $rowCells[] = $cell;
                    $rowMarkers[] = $cell->getSpanMarker();
                }
            }
            $cells[] = $rowCells;
            $markers[] = $rowMarkers;
        }

        $grid = [];
        foreach (self::resolveMarkers($markers) as $r => $spans) {
            $gridRow = [];
            foreach ($spans as $c => $span) {
                $gridRow[] = ['cell' => $cells[$r][$c]] + $span;
            }
            $grid[] = $gridRow;
        }

        return $grid;
    }

    /**
     * Run the walk over a grid of markers (`^`, `<` or null per position) and
     * return each position's resolved extent.
     *
     * @param list<list<string|null>> $markers
     *
     * @return list<list<array{rowspan: int, colspan: int, skip: bool}>>
     */
    public static function resolveMarkers(array $markers): array
    {
        /** @var array<int, array<int, int>> $rowspans Extra rows per origin, keyed by row then column. */
        $rowspans = [];
        $walks = [];
        /** @var array<int, int> $above */
        $above = [];
        /** @var array<int, int> $originRow Row index of the cell $above names, per left edge. */
        $originRow = [];
        foreach ($markers as $r => $row) {
            $walk = self::walkRow($row, $above);
            foreach ($walk['extends'] as $c) {
                $rowspans[$originRow[$c]][$c] = ($rowspans[$originRow[$c]][$c] ?? 0) + 1;
            }
            foreach ($walk['colspan'] as $c => $_) {
                $originRow[$c] = $r;
            }
            $above = $walk['below'];
            $walks[] = $walk;
        }

        $grid = [];
        foreach ($markers as $r => $row) {
            $gridRow = [];
            foreach ($row as $c => $_) {
                $gridRow[] = [
                    'rowspan' => 1 + ($rowspans[$r][$c] ?? 0),
                    'colspan' => $walks[$r]['colspan'][$c] ?? 1,
                    'skip' => isset($walks[$r]['skip'][$c]),
                ];
            }
            $grid[] = $gridRow;
        }

        return $grid;
    }

    /**
     * Resolve one row of markers in row-major order.
     *
     * $above maps each column c to the colspan of the cell that covers
     * (r-1, c) and whose left edge is c; any other column has no origin a `^`
     * can extend.
     *
     * - `<` at c widens the cell covering (r, c-1) only when that cell starts
     *   in this row.
     * - `^` at c grows the cell named by $above[c] by one row only when every
     *   other position of the new row strip is a span marker or no cell; the
     *   strip's markers resolve into it and render nothing.
     * - A marker that cannot resolve stays a cell of its own (rendered empty).
     *
     * @param array<int, string|null> $markers Per position: `^`, `<` or null.
     * @param array<int, int> $above
     *
     * @return array{colspan: array<int, int>, skip: array<int, true>, extends: list<int>, below: array<int, int>, colspanSkips: list<int>, rowspanSkips: list<int>}
     */
    public static function walkRow(array $markers, array $above): array
    {
        $count = count($markers);
        /** @var array<int, int> $colspan Width of each cell that starts in this row. */
        $colspan = [];
        /** @var array<int, true> $skip */
        $skip = [];
        $extends = [];
        $below = [];
        $colspanSkips = [];
        $rowspanSkips = [];
        /** @var array<int, int> $startOf Start column of the row's own cell covering a position; -1 for a cell from above. */
        $startOf = [];
        for ($c = 0; $c < $count; $c++) {
            if (isset($skip[$c])) {
                continue;
            }
            $marker = $markers[$c] ?? null;
            if ($marker === '<' && $c > 0 && ($startOf[$c - 1] ?? -1) >= 0) {
                $start = $startOf[$c - 1];
                $colspan[$start]++;
                $skip[$c] = true;
                $startOf[$c] = $start;
                $colspanSkips[] = $c;

                continue;
            }
            if ($marker === '^' && isset($above[$c]) && self::stripIsFree($markers, $c, $above[$c])) {
                $extends[] = $c;
                $below[$c] = $above[$c];
                $end = min($count, $c + $above[$c]);
                for ($k = $c; $k < $end; $k++) {
                    $skip[$k] = true;
                    $startOf[$k] = -1;
                    $rowspanSkips[] = $k;
                }

                continue;
            }
            $startOf[$c] = $c;
            $colspan[$c] = 1;
        }
        foreach ($colspan as $c => $width) {
            $below[$c] = $width;
        }
        ksort($below);

        return [
            'colspan' => $colspan,
            'skip' => $skip,
            'extends' => $extends,
            'below' => $below,
            'colspanSkips' => $colspanSkips,
            'rowspanSkips' => $rowspanSkips,
        ];
    }

    /**
     * @param array<int, string|null> $markers
     * @param int $c
     * @param int $colspan
     */
    private static function stripIsFree(array $markers, int $c, int $colspan): bool
    {
        $end = min(count($markers), $c + $colspan);
        for ($k = $c + 1; $k < $end; $k++) {
            if (($markers[$k] ?? null) === null) {
                return false;
            }
        }

        return true;
    }
}
