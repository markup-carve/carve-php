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
        $cells = self::placed($table, $groups);
        $marks = [];
        $hasHead = false;
        foreach ($cells as [$g, $r, $c, $column, $head]) {
            $hasHead = $hasHead || $head;
            $align = $groups[$g][$r][$c]['align'] ?? null;
            if ($head && is_string($align)) {
                $marks[$column] = $align;
            }
        }
        $byColumn = [];
        foreach ($cells as [$g, $r, $c, $column, $head]) {
            $align = $groups[$g][$r][$c]['align'] ?? null;
            $mark = $marks[$column] ?? null;
            if ($head) {
                continue;
            }
            if ($mark !== null && $align === $mark) {
                unset($groups[$g][$r][$c]['align']);
            } elseif ($mark === null) {
                $byColumn[$column][] = [$g, $r, $c, is_string($align) ? $align : null];
            }
        }
        if (!$hasHead) {
            $table['columns'] = self::columns(J::nodes($table, 'columns'), $byColumn, $groups);
        }

        return self::store($table, $groups);
    }

    /**
     * In a table with no head, a column whose cells all show one alignment has it as its own.
     *
     * @param array<array<string, mixed>> $columns
     * @param array<int, array<array{0: int, 1: int, 2: int, 3: string|null}>> $byColumn
     * @param array<int, array<int, array<array<string, mixed>>>> $groups
     *
     * @return array<array<string, mixed>>
     */
    private static function columns(array $columns, array $byColumn, array &$groups): array
    {
        foreach ($byColumn as $column => $entries) {
            $aligns = array_unique(array_map(static fn (array $e): string => $e[3] ?? '', $entries));
            if (!isset($columns[$column]) || count($aligns) !== 1 || $entries[0][3] === null) {
                continue;
            }
            $columns[$column]['align'] = $entries[0][3];
            foreach ($entries as [$g, $r, $c]) {
                unset($groups[$g][$r][$c]['align']);
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
     * The cells of the table with the column each starts in, as a render places them (C2-H-056).
     *
     * @param array<string, mixed> $table
     * @param array<int, array<int, array<array<string, mixed>>>> $groups
     *
     * @return array<array{0: int, 1: int, 2: int, 3: int, 4: bool}>
     */
    private static function placed(array $table, array $groups): array
    {
        $out = [];
        foreach (J::nodes($table) as $g => $group) {
            $covered = [];
            $head = ($group['type'] ?? null) === 'table_head';
            foreach ($groups[$g] ?? [] as $r => $cells) {
                $column = 0;
                foreach ($cells as $c => $cell) {
                    while (($covered[$column] ?? 0) > 0) {
                        $covered[$column]--;
                        $column++;
                    }
                    $out[] = [$g, $r, $c, $column, $head];
                    $span = J::int($cell, 'colspan') ?? 1;
                    for ($more = 0; $more < $span; $more++) {
                        $covered[$column + $more] = (J::int($cell, 'rowspan') ?? 1) - 1;
                    }
                    $column += $span;
                }
            }
        }

        return $out;
    }
}
