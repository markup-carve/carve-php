<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;

/**
 * Resolves a table's `^`/`<` placeholder cells into a rendering grid.
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
