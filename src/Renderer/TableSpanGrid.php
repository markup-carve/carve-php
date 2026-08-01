<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;

/**
 * Resolves a table's `^`/`<` placeholder cells into a rendering grid.
 *
 * carve-php#527: the parser keeps a real, empty `table_cell` for every span
 * marker rather than folding it into the origin cell as a `rowspan`/`colspan`
 * count (carve-js parity - a consumer walking `rows[i].cells` gets the same
 * length for every row). That means a row no longer tells a renderer, by
 * itself, which of its cells are real content and which are placeholders a
 * span already claimed; this is the one place that answers it, by walking the
 * same single LEFT-TO-RIGHT, TOP-TO-BOTTOM grid the parser and carve-js both
 * use (carve spec section 96):
 *
 *  - a cell marked `^` extends the nearest non-skipped cell directly ABOVE it
 *    in the same column, if one is open; that cell's computed rowspan grows
 *    by one and this cell is marked skip.
 *  - a cell marked `<` extends the nearest non-skipped cell to its LEFT in the
 *    same row (scanning past columns already claimed by another span); that
 *    cell's computed colspan grows by one and this cell is marked skip.
 *  - any other cell - including a marker with no valid target, a degenerate
 *    marker - is never skipped: it renders as its own (typically empty) cell.
 *
 * A "skip" entry contributes no output element of its own; the cell it
 * extended reports the grown rowspan/colspan instead. This is the ONLY
 * consumer that should read `TableCell::getSpanMarker()` to decide whether a
 * cell is real or a placeholder - every other table renderer walks this
 * grid's output instead of `TableRow::getChildren()` directly, so none of
 * them re-implement the walk or risk double-counting a placeholder as an
 * extra column.
 */
final class TableSpanGrid
{
    /**
     * @return list<list<array{cell: \MarkupCarve\Carve\Node\Block\TableCell, rowspan: int, colspan: int, skip: bool}>>
     */
    public static function resolve(Table $table): array
    {
        $rows = [];
        foreach ($table->getChildren() as $row) {
            if (!$row instanceof TableRow) {
                continue;
            }
            $gridRow = [];
            foreach ($row->getChildren() as $cell) {
                if ($cell instanceof TableCell) {
                    $gridRow[] = ['cell' => $cell, 'rowspan' => 1, 'colspan' => 1, 'skip' => false];
                }
            }
            $rows[] = $gridRow;
        }

        $rowCount = count($rows);
        /** @var array<int, int> $lastNonSkip */
        $lastNonSkip = [];
        for ($r = 0; $r < $rowCount; $r++) {
            $colCount = count($rows[$r]);
            for ($c = 0; $c < $colCount; $c++) {
                /** @var array{cell: \MarkupCarve\Carve\Node\Block\TableCell, rowspan: int, colspan: int, skip: bool} $entry */
                $entry = $rows[$r][$c];
                if ($entry['skip']) {
                    continue;
                }
                $marker = $entry['cell']->getSpanMarker();
                if ($marker === '^' && $r > 0 && isset($lastNonSkip[$c])) {
                    $up = $lastNonSkip[$c];
                    /** @var array{cell: \MarkupCarve\Carve\Node\Block\TableCell, rowspan: int, colspan: int, skip: bool} $origin */
                    $origin = $rows[$up][$c];
                    $origin['rowspan']++;
                    $rows[$up][$c] = $origin;
                    $entry['skip'] = true;
                } elseif ($marker === '<' && $c > 0) {
                    $left = $c - 1;
                    while ($left >= 0 && $rows[$r][$left]['skip']) {
                        $left--;
                    }
                    if ($left >= 0) {
                        /** @var array{cell: \MarkupCarve\Carve\Node\Block\TableCell, rowspan: int, colspan: int, skip: bool} $target */
                        $target = $rows[$r][$left];
                        $target['colspan']++;
                        $rows[$r][$left] = $target;
                        $entry['skip'] = true;
                    }
                }
                $rows[$r][$c] = $entry;
                if (!$entry['skip']) {
                    $lastNonSkip[$c] = $r;
                }
            }
        }

        return $rows;
    }
}
