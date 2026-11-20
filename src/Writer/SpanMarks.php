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
        $counts = false;
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $group) {
            foreach (is_array($group) && ($group['type'] ?? '') !== 'comment' ? (array)($group['children'] ?? []) : [] as $row) {
                foreach (is_array($row) && ($row['type'] ?? '') === 'table_row' ? (array)($row['children'] ?? []) : [] as $cell) {
                    if (!is_array($cell)) {
                        continue;
                    }
                    if (isset($cell['span'])) {
                        return false;
                    }
                    $counts = $counts || self::count($cell, 'colspan') > 1 || self::count($cell, 'rowspan') > 1;
                }
            }
        }

        return $counts;
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
        foreach (is_array($row['children'] ?? null) ? $row['children'] : [] as $k => $cell) {
            if (!is_array($cell)) {
                continue;
            }
            $changed = self::takePending($pending, $column, $out) || $changed;
            $at = $path . '/children/' . $k;
            $out[] = [$cell, $at];
            $colspan = self::count($cell, 'colspan');
            for ($n = 1; $n < $colspan; $n++) {
                $out[] = [self::mark('colspan'), $at];
                $changed = true;
            }
            $rowspan = self::count($cell, 'rowspan');
            if ($rowspan > 1) {
                $pending[$column] = [$at, $rowspan - 1, $colspan];
                if ($colspan > 1) {
                    $unspelled[] = $at;
                }
            }
            $column += $colspan;
        }
        $changed = self::takePending($pending, $column, $out) || $changed;
        // A row span that no cell of this row reaches has no place in it.
        foreach ($pending as $start => $held) {
            if ($start >= $column && $held[1] > 0) {
                $pending[$start][1]--;
            }
        }

        return $changed ? $out : null;
    }

    /**
     * The marks of the row spans from above that hold the columns from `$column` on.
     *
     * @param array<int, array{0: string, 1: int, 2: int}> $pending
     * @param int $column
     * @param array<array{0: array<mixed>, 1: string}> $out
     *
     * @return bool Whether a mark was added.
     */
    private static function takePending(array &$pending, int &$column, array &$out): bool
    {
        $added = false;
        while (isset($pending[$column]) && $pending[$column][1] > 0) {
            [$at, , $width] = $pending[$column];
            // A `^` below a cell of more than one column joins nothing, so that row span stays unspelled.
            if ($width === 1) {
                $out[] = [self::mark('rowspan'), $at];
                $added = true;
            }
            $pending[$column][1]--;
            $column += $width;
        }

        return $added;
    }
}
