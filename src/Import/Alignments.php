<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

/**
 * C2-M-040, C2-B-213 read backward: a render writes on every cell the alignment it resolves from the cell, the head and
 * the column, so the import gives each alignment back to the place it came from.
 */
final class Alignments
{
    /**
     * @param array<string, mixed> $table
     *
     * @return array<string, mixed>
     */
    public static function resolve(array $table): array
    {
        $groups = [];
        foreach (J::nodes($table) as $g => $group) {
            foreach (J::nodes($group) as $r => $row) {
                $groups[$g][$r] = J::nodes($row);
            }
        }
        $cells = self::placed(J::nodes($table), $groups);
        foreach (['align', 'valign'] as $axis) {
            $marks = [];
            $hasHead = false;
            foreach ($cells as [$g, $r, $c, $column, $head]) {
                $hasHead = $hasHead || $head;
                $alignment = $groups[$g][$r][$c][$axis] ?? null;
                if ($head && is_string($alignment)) {
                    $marks[$column] = $alignment;
                }
            }
            $byColumn = [];
            foreach ($cells as [$g, $r, $c, $column, $head]) {
                $alignment = $groups[$g][$r][$c][$axis] ?? null;
                $mark = $marks[$column] ?? null;
                if ($head) {
                    continue;
                }
                if ($mark !== null && $alignment === $mark) {
                    unset($groups[$g][$r][$c][$axis]);
                } elseif ($mark === null) {
                    $byColumn[$column][] = [$g, $r, $c, is_string($alignment) ? $alignment : null];
                }
            }
            if (!$hasHead) {
                $table['columns'] = self::columns(J::nodes($table, 'columns'), $byColumn, $groups, $axis);
            }
        }

        return self::store($table, $groups);
    }

    /**
     * In a table with no head, a column whose cells all show one alignment has it as its own, but only while every
     * column before it has one too, as the list has no empty entry (C2-B-211); any other cell keeps its own.
     *
     * @param array<array<string, mixed>> $columns
     * @param array<int, array<array{0: int, 1: int, 2: int, 3: string|null}>> $byColumn
     * @param array<int, array<int, array<array<string, mixed>>>> $groups
     * @param string $axis
     *
     * @return array<array<string, mixed>>
     */
    private static function columns(array $columns, array $byColumn, array &$groups, string $axis): array
    {
        foreach (array_keys($columns) as $column) {
            $entries = $byColumn[$column] ?? [];
            $aligns = array_unique(array_map(static fn (array $e): string => $e[3] ?? '', $entries));
            // `baseline` has no `valigns` entry (C2-B-211), so its cells keep it, and so do those of every later column.
            if (count($aligns) !== 1 || $entries[0][3] === null || $entries[0][3] === 'baseline') {
                break;
            }
            $columns[$column][$axis] = $entries[0][3];
            foreach ($entries as [$g, $r, $c]) {
                unset($groups[$g][$r][$c][$axis]);
            }
        }

        return $columns;
    }

    /**
     * @param array<string, mixed> $table
     * @param array<int, array<int, array<array<string, mixed>>>> $groups
     *
     * @return array<string, mixed>
     */
    private static function store(array $table, array $groups): array
    {
        $children = J::nodes($table);
        foreach ($children as $g => $group) {
            $rows = J::nodes($group);
            foreach ($rows as $r => $row) {
                $row['children'] = $groups[$g][$r] ?? [];
                $rows[$r] = $row;
            }
            $group['children'] = $rows;
            $children[$g] = $group;
        }
        $table['children'] = $children;

        return $table;
    }

    /**
     * C2-M-039: the columns a parse counts (C2-B-172), with the slots that row spans from above take in each row, placed
     * as `placed` places them.
     *
     * @param array<array<string, mixed>> $groups
     *
     * @return int
     */
    public static function gridWidth(array $groups): int
    {
        $width = 0;
        foreach ($groups as $group) {
            $freeAt = [];
            foreach (J::nodes($group) as $r => $row) {
                $column = 0;
                foreach (J::nodes($row) as $cell) {
                    while (($freeAt[$column] ?? 0) > $r) {
                        $column++;
                    }
                    $span = J::int($cell, 'colspan') ?? 1;
                    $rowspan = J::int($cell, 'rowspan') ?? 1;
                    if ($rowspan > 1) {
                        for ($more = 0; $more < $span; $more++) {
                            $freeAt[$column + $more] = max($freeAt[$column + $more] ?? 0, $r + $rowspan);
                        }
                    }
                    $column += $span;
                }
                $width = max($width, $column);
            }
        }

        return $width;
    }

    /**
     * The cells of the table with the column each starts in, as a render places them (C2-H-056). `$freeAt` holds, per
     * column, the first row of the group that a row span from above no longer covers.
     *
     * @param array<array<string, mixed>> $groupNodes
     * @param array<int, array<int, array<array<string, mixed>>>> $groups
     *
     * @return array<array{0: int, 1: int, 2: int, 3: int, 4: bool}>
     */
    private static function placed(array $groupNodes, array $groups): array
    {
        $out = [];
        foreach ($groupNodes as $g => $group) {
            $freeAt = [];
            $head = ($group['type'] ?? null) === 'table_head';
            foreach ($groups[$g] ?? [] as $r => $cells) {
                $column = 0;
                foreach ($cells as $c => $cell) {
                    while (($freeAt[$column] ?? 0) > $r) {
                        $column++;
                    }
                    $out[] = [$g, $r, $c, $column, $head];
                    $span = J::int($cell, 'colspan') ?? 1;
                    $end = $r + (J::int($cell, 'rowspan') ?? 1);
                    for ($more = 0; $more < $span; $more++) {
                        $freeAt[$column + $more] = max($freeAt[$column + $more] ?? 0, $end);
                    }
                    $column += $span;
                }
            }
        }

        return $out;
    }
}
