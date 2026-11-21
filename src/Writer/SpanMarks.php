<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * C2-W-145: a span count that no mark cell covers, as an import or a bridge gives it (C2-A-093), is written with the
 * marks a parse reads back as that count. The rows are written from cells with the marks added; an added mark keeps
 * the path of its covering cell, so a loss names the supplied tree.
 */
final class SpanMarks
{
    /**
     * @param array<string, array<array{0: array<mixed>, 1: string}>> $rows The cells of each changed row, by row path.
     * @param array<string> $unspelled The paths of the cells whose row span has no spelling.
     */
    private function __construct(public readonly array $rows, public readonly array $unspelled)
    {
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     *
     * @return self|null
     */
    public static function of(array $node, string $path): ?self
    {
        if (!self::implicit($node)) {
            return null;
        }
        $rows = [];
        $unspelled = [];
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $g => $group) {
            if (!is_array($group) || ($group['type'] ?? '') === 'comment') {
                continue;
            }
            self::group($group, $path . '/children/' . $g, $rows, $unspelled);
        }

        return new self($rows, $unspelled);
    }

    /**
     * @param array<mixed> $node
     *
     * @return bool
     */
    private static function implicit(array $node): bool
    {
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $group) {
            foreach (is_array($group) && ($group['type'] ?? '') !== 'comment' ? (array)($group['children'] ?? []) : [] as $row) {
                foreach (is_array($row) && ($row['type'] ?? '') === 'table_row' ? (array)($row['children'] ?? []) : [] as $cell) {
                    if (is_array($cell) && (self::count($cell, 'colspan') > 1 || self::count($cell, 'rowspan') > 1)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $cell
     * @param string $field
     *
     * @return int
     */
    private static function count(array $cell, string $field): int
    {
        return is_int($cell[$field] ?? null) ? $cell[$field] : 1;
    }

    /**
     * @param string $span
     *
     * @return array<mixed>
     */
    private static function mark(string $span): array
    {
        return ['type' => 'table_cell', 'header' => false, 'span' => $span, 'children' => []];
    }

    /**
     * The cells of each row of one group with the marks of its counts, by the column counting of C2-B-172.
     *
     * @param array<mixed> $group
     * @param string $path
     * @param array<string, array<array{0: array<mixed>, 1: string}>> $rows
     * @param array<string> $unspelled
     *
     * @return void
     */
    private static function group(array $group, string $path, array &$rows, array &$unspelled): void
    {
        // The row span from above that holds each column: the path of its cell, the rows it still takes, its width.
        $pending = [];
        foreach (is_array($group['children'] ?? null) ? $group['children'] : [] as $r => $row) {
            if (!is_array($row) || ($row['type'] ?? '') !== 'table_row') {
                continue;
            }
            $rowPath = $path . '/children/' . $r;
            $cells = self::row($row, $rowPath, $pending, $unspelled);
            if ($cells !== null) {
                $rows[$rowPath] = $cells;
            }
        }
        // A row span that reaches past the last row of its group has no row for its marks.
        foreach ($pending as [$at, $left]) {
            if ($left > 0 && !in_array($at, $unspelled, true)) {
                $unspelled[] = $at;
            }
        }
    }

    /**
     * @param array<mixed> $row
     * @param string $path
     * @param array<int, array{0: string, 1: int, 2: int}> $pending
     * @param array<string> $unspelled
     *
     * @return array<array{0: array<mixed>, 1: string}>|null The cells with the marks, or null where it needs none.
     */
    private static function row(array $row, string $path, array &$pending, array &$unspelled): ?array
    {
        $out = [];
        $column = 0;
        $changed = false;
        $cells = array_values(array_filter(is_array($row['children'] ?? null) ? $row['children'] : [], 'is_array'));
        $keys = array_keys(array_filter(is_array($row['children'] ?? null) ? $row['children'] : [], 'is_array'));
        foreach ($cells as $n => $cell) {
            $changed = self::takePending($pending, $column, $out, $cell, $unspelled) || $changed;
            $at = $path . '/children/' . $keys[$n];
            $out[] = [$cell, $at];
            if (($cell['span'] ?? null) === 'rowspan' && isset($pending[$column])) {
                $pending[$column][1]--;
            }
            $colspan = self::count($cell, 'colspan');
            $missing = self::addColumns($cells, $n, $colspan, $at, $out);
            $changed = $changed || $missing > 0;
            $rowspan = self::count($cell, 'rowspan');
            if ($rowspan > 1) {
                $pending[$column] = [$at, $rowspan - 1, $colspan];
            }
            $column += 1 + $missing;
        }
        $changed = self::takePending($pending, $column, $out, null, $unspelled) || $changed;
        // A row span that no cell of this row reaches has no place in it.
        foreach ($pending as $start => $held) {
            if ($start >= $column && $held[1] > 0) {
                $pending[$start][1]--;
                if (!in_array($held[0], $unspelled, true)) {
                    $unspelled[] = $held[0];
                }
            }
        }

        return $changed ? $out : null;
    }

    /**
     * Adds the `<` marks of the cell `$cells[$n]` that the tree does not hold behind it. Returns how many it added.
     *
     * @param array<array<mixed>> $cells
     * @param int $n
     * @param int $colspan
     * @param string $at The path of the cell.
     * @param array<array{0: array<mixed>, 1: string}> $out
     *
     * @return int
     */
    private static function addColumns(array $cells, int $n, int $colspan, string $at, array &$out): int
    {
        if (isset($cells[$n]['span'])) {
            return 0;
        }
        $missing = $colspan - 1 - self::markedColumns($cells, $n, $colspan);
        for ($k = 0; $k < $missing; $k++) {
            $out[] = [self::mark('colspan'), $at];
        }

        return $missing;
    }

    /**
     * How many of the `$colspan - 1` cells behind `$cells[$at]` are its marks.
     *
     * @param array<array<mixed>> $cells
     * @param int $at
     * @param int $colspan
     *
     * @return int
     */
    private static function markedColumns(array $cells, int $at, int $colspan): int
    {
        $held = 0;
        while ($held < $colspan - 1 && ($cells[$at + 1 + $held]['span'] ?? null) === 'colspan') {
            $held++;
        }

        return $held;
    }

    /**
     * The marks of the row spans from above that hold the columns from `$column` on, before the cell `$next`.
     *
     * @param array<int, array{0: string, 1: int, 2: int}> $pending
     * @param int $column
     * @param array<array{0: array<mixed>, 1: string}> $out
     * @param array<mixed>|null $next
     * @param array<string> $unspelled
     *
     * @return bool Whether a mark was added.
     */
    private static function takePending(array &$pending, int &$column, array &$out, ?array $next, array &$unspelled): bool
    {
        $added = false;
        // A `^` the tree holds at the column is the mark of its row, so none is added.
        while (isset($pending[$column]) && $pending[$column][1] > 0 && ($next['span'] ?? null) !== 'rowspan') {
            [$at, , $width] = $pending[$column];
            // A `^` below a cell of more than one column joins nothing, so that row span stays unspelled.
            if ($width === 1) {
                $out[] = [self::mark('rowspan'), $at];
                $added = true;
            } elseif (!in_array($at, $unspelled, true)) {
                $unspelled[] = $at;
            }
            $pending[$column][1]--;
            $column += $width;
        }

        return $added;
    }
}
