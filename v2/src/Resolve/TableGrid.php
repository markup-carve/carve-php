<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Resolve;

use MarkupCarve\Carve2\Node;

/**
 * The cell grid of a table: every row of every group in order, and the column each cell starts in.
 * With span marks in the tree (C2-B-172) every cell, a mark cell included, holds one slot; without
 * them the counts of a supplied tree place the cells.
 */
final class TableGrid
{
    /**
     * Rows in order, each with its group node.
     *
     * @var array<array{0: \MarkupCarve\Carve2\Node, 1: \MarkupCarve\Carve2\Node}>
     */
    public array $rows = [];

    /**
     * Starting column per cell, by spl_object_id.
     *
     * @var array<int, int>
     */
    public array $column = [];

    /**
     * Row index per cell, by spl_object_id.
     *
     * @var array<int, int>
     */
    public array $rowIndex = [];

    public bool $marks = false;

    /**
     * @param \MarkupCarve\Carve2\Node $table
     *
     * @return self
     */
    public static function of(Node $table): self
    {
        $grid = new self();
        foreach ($table->children ?? [] as $group) {
            if ($group->type !== 'table_head' && $group->type !== 'table_body' && $group->type !== 'table_foot') {
                continue;
            }
            foreach ($group->children ?? [] as $row) {
                if ($row->type !== 'table_row') {
                    continue;
                }
                $grid->rows[] = [$row, $group];
                foreach ($row->children ?? [] as $cell) {
                    if (isset($cell->f['span'])) {
                        $grid->marks = true;
                    }
                }
            }
        }
        $taken = [];
        foreach ($grid->rows as $r => [$row]) {
            $c = 0;
            foreach ($row->children ?? [] as $cell) {
                if ($cell->type !== 'table_cell') {
                    continue;
                }
                while (isset($taken[$r][$c])) {
                    $c++;
                }
                $id = spl_object_id($cell);
                $grid->column[$id] = $c;
                $grid->rowIndex[$id] = $r;
                $cols = $grid->marks ? 1 : self::count($cell, 'colspan');
                $rows = $grid->marks ? 1 : self::count($cell, 'rowspan');
                for ($i = 0; $i < $rows; $i++) {
                    for ($j = 0; $j < $cols; $j++) {
                        $taken[$r + $i][$c + $j] = true;
                    }
                }
                $c += $cols;
            }
        }

        return $grid;
    }

    /**
     * @param \MarkupCarve\Carve2\Node $cell
     * @param string $field
     *
     * @return int
     */
    public static function count(Node $cell, string $field): int
    {
        $v = $cell->f[$field] ?? 1;

        return is_int($v) && $v > 1 ? min($v, 1000) : 1;
    }

    /**
     * C2-B-172, C2-B-183: derives colspan and rowspan of the covering cells from the span marks.
     *
     * @return void
     */
    public function deriveSpans(): void
    {
        if (!$this->marks) {
            return;
        }
        $owner = [];
        $cells = [];
        $ends = [];
        foreach ($this->rows as $r => [$row]) {
            foreach ($row->children ?? [] as $cell) {
                if ($cell->type !== 'table_cell') {
                    continue;
                }
                $id = spl_object_id($cell);
                $c = $this->column[$id];
                $span = $cell->f['span'] ?? null;
                $o = null;
                if ($span === 'colspan') {
                    $o = $owner[$r][$c - 1] ?? null;
                } elseif ($span === 'rowspan') {
                    $o = $owner[$r - 1][$c] ?? null;
                }
                if ($o === null) {
                    $o = $id;
                    $cells[$id] = $cell;
                    $ends[$id] = [$r, $c];
                } else {
                    $ends[$o] = [max($ends[$o][0], $r), max($ends[$o][1], $c)];
                }
                $owner[$r][$c] = $o;
            }
        }
        foreach ($cells as $id => $cell) {
            unset($cell->f['colspan'], $cell->f['rowspan']);
            $cols = $ends[$id][1] - $this->column[$id] + 1;
            $rows = $ends[$id][0] - $this->rowIndex[$id] + 1;
            if ($cols > 1) {
                $cell->f['colspan'] = $cols;
            }
            if ($rows > 1) {
                $cell->f['rowspan'] = $rows;
            }
        }
    }
}
