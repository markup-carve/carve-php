<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Include;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Block\Frame;
use MarkupCarve\Carve\Block\TableBuilder;
use MarkupCarve\Carve\Node;

final class TableInclude
{
    /**
     *      * @param Node $include
     *
     * @param \MarkupCarve\Carve\Node $include
     * @param string $source
     * @param int $start
@param array<string, mixed> $options
     * @param int $lastByte
     * @param int $firstByte
     *
     * @return array{0: \MarkupCarve\Carve\Node|null, 1: string|null}
     */
    public static function build(Node $include, string $source, int $start, array $options, int $firstByte, int $lastByte): array
    {
        $attrs = $include->f['attrs'] ?? null;
        $attrs = $attrs instanceof Attrs ? $attrs : null;
        $frame = new Frame(Frame::CODE, $start + $firstByte);
        $last = substr($source, $firstByte, $lastByte - $firstByte);
        $frame->end = $start + $lastByte - (str_ends_with($last, "\r\n") ? 2 : (str_ends_with($last, "\n") ? 1 : 0));
        $frame->attrs = $attrs === null ? null : clone $attrs;
        $separator = ($options['code'] ?? null) === 'tsv' ? "\t" : ',';
        [$records, $starts] = self::withoutMarkers($source, $separator);
        $table = TableBuilder::delimited($frame, $records, $separator);
        $error = self::select($table, $options, $starts, $firstByte, $lastByte, $attrs);
        if ($error !== null) {
            return [null, $error];
        }
        if (($include->f['caption'] ?? null) instanceof Node) {
            $table->f['caption'] = self::copyCaption($include->f['caption']);
        }

        return [$table, null];
    }

    /**
     * @param \MarkupCarve\Carve\Node $table
     * @param array<string, mixed> $options
     * @param array<int> $starts
     * @param \MarkupCarve\Carve\Attrs|null $attrs
     * @param int $lastByte
     * @param int $firstByte
     */
    private static function select(Node $table, array $options, array $starts, int $firstByte, int $lastByte, ?Attrs $attrs): ?string
    {
        $rows = self::rows($table);
        if (is_string($options['rows'] ?? null)) {
            [$first, $last] = array_map('intval', explode('-', $options['rows'], 2));
            if ($first > count($rows)) {
                return 'include-rows-out-of-range';
            }
            self::selectRows($table, $first, $last);
        } elseif (isset($options['lines']) || isset($options['region'])) {
            self::selectInterval($table, $starts, $firstByte, $lastByte);
        }
        if (isset($options['cols'])) {
            $error = self::columns($table, $options['cols'], $attrs);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * @return array<int>
     */
    private static function recordStarts(string $source, string $separator): array
    {
        $starts = [];
        $quoted = false;
        $fieldStart = true;
        $recordStart = 0;
        for ($i = 0, $n = strlen($source); $i < $n; $i++) {
            $ch = $source[$i];
            if ($ch === '"') {
                if ($quoted && $i + 1 < $n && $source[$i + 1] === '"') {
                    $i++;
                } elseif ($quoted || $fieldStart) {
                    $quoted = !$quoted;
                }
            } elseif (!$quoted && $ch === $separator) {
                $fieldStart = true;
            } elseif (!$quoted && $ch === "\n") {
                $starts[] = $recordStart;
                $recordStart = $i + 1;
                $fieldStart = true;
            } else {
                $fieldStart = false;
            }
        }
        if ($recordStart < strlen($source)) {
            $starts[] = $recordStart;
        }

        return $starts;
    }

    /**
     * @return array{0: string, 1: array<int>}
     */
    private static function withoutMarkers(string $source, string $separator): array
    {
        $starts = self::recordStarts($source, $separator);
        $kept = [];
        $text = '';
        $length = strlen($source);
        foreach ($starts as $index => $start) {
            $end = $starts[$index + 1] ?? $length;
            $record = substr($source, $start, $end - $start);
            if (substr_count($record, "\n") <= 1 && is_array(Selection::marker($record, true))) {
                continue;
            }
            $text .= $record;
            $kept[] = $start;
        }

        return [$text, $kept];
    }

    public static function malformed(string $source, string $separator): bool
    {
        $quoted = false;
        $fieldStart = true;
        for ($i = 0, $n = strlen($source); $i < $n; $i++) {
            $ch = $source[$i];
            if ($ch === '"') {
                if ($quoted && $i + 1 < $n && $source[$i + 1] === '"') {
                    $i++;
                } elseif ($quoted || $fieldStart) {
                    $quoted = !$quoted;
                }
            } elseif (!$quoted && ($ch === $separator || $ch === "\n")) {
                $fieldStart = true;
            } else {
                $fieldStart = false;
            }
        }

        return $quoted;
    }

    /**
     * @param \MarkupCarve\Carve\Node $table
@param array<int> $starts
     * @param int $last
     * @param int $first
     */
    private static function selectInterval(Node $table, array $starts, int $first, int $last): void
    {
        $index = 0;
        foreach ($table->children ?? [] as $group) {
            if ($group->type !== 'table_body') {
                $index += count($group->children ?? []);

                continue;
            }
            $selected = [];
            foreach ($group->children ?? [] as $row) {
                $start = $starts[$index] ?? -1;
                if ($start >= $first && $start < $last) {
                    $selected[] = $row;
                }
                $index++;
            }
            $group->children = $selected;
        }
    }

    public static function copyCaption(Node $caption): Node
    {
        $copy = clone $caption;
        $copy->start = -1;
        $copy->end = -1;
        $copy->children = $caption->children === null ? null : [];
        foreach ($caption->children ?? [] as $child) {
            $copy->children[] = self::copyCaption($child);
        }

        return $copy;
    }

    /**
     * @return array<\MarkupCarve\Carve\Node>
     */
    private static function rows(Node $table): array
    {
        $rows = [];
        foreach ($table->children ?? [] as $group) {
            foreach ($group->children ?? [] as $row) {
                if ($row->type === 'table_row') {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    private static function selectRows(Node $table, int $first, int $last): void
    {
        $index = 0;
        foreach ($table->children ?? [] as $group) {
            if ($group->type !== 'table_body') {
                $index += count($group->children ?? []);

                continue;
            }
            $selected = [];
            foreach ($group->children ?? [] as $row) {
                $index++;
                if ($index >= $first && $index <= $last) {
                    $selected[] = $row;
                }
            }
            $group->children = $selected;
        }
    }

    private static function columns(Node $table, mixed $spec, ?Attrs $attrs): ?string
    {
        $value = $table->f['columns'] ?? null;
        $columns = is_array($value) ? array_values(array_filter($value, static fn (mixed $item): bool => $item instanceof Node)) : [];
        if (is_string($spec)) {
            $indexes = self::rangeColumns($spec, count($columns), $attrs);
        } elseif (is_array($spec)) {
            $indexes = self::namedColumns($table, $spec);
        } else {
            return 'include-cols-invalid';
        }
        if (is_string($indexes)) {
            return $indexes;
        }
        $table->f['columns'] = array_map(static fn (int $i): Node => $columns[$i], $indexes);
        foreach (self::rows($table) as $row) {
            $cells = $row->children ?? [];
            $row->children = array_map(static fn (int $i): Node => $cells[$i] ?? new Node('table_cell', []), $indexes);
        }

        return null;
    }

    /**
     * @return array<int>|string
     */
    private static function rangeColumns(string $spec, int $width, ?Attrs $attrs): array|string
    {
        if (TableBuilder::count($attrs, 'header-rows') !== 0) {
            return 'include-cols-invalid';
        }
        [$first, $last] = array_map('intval', explode('-', $spec, 2));
        if ($first > $width) {
            return 'include-cols-invalid';
        }

        return range($first - 1, min($last, $width) - 1);
    }

    /**
     *      * @param Node $table
     *     * @param Node $table
     *
     * @param \MarkupCarve\Carve\Node $table
@param array<mixed> $requested
     *
     * @return array<int>|string
     */
    private static function namedColumns(Node $table, array $requested): array|string
    {
        $head = $table->children[0] ?? null;
        $firstRow = $head instanceof Node && $head->type === 'table_head' ? ($head->children[0] ?? null) : null;
        if (!$firstRow instanceof Node) {
            return 'include-cols-invalid';
        }
        $names = [];
        foreach ($firstRow->children ?? [] as $index => $cell) {
            $names[self::cellText($cell)][] = $index;
        }
        $indexes = [];
        foreach ($requested as $name) {
            if (!is_string($name) || !isset($names[$name]) || count($names[$name]) !== 1) {
                return 'include-cols-invalid';
            }
            $indexes[] = $names[$name][0];
        }

        return count(array_unique($indexes)) === count($indexes) ? $indexes : 'include-cols-duplicate';
    }

    private static function cellText(Node $cell): string
    {
        $name = '';
        foreach ($cell->children ?? [] as $para) {
            foreach ($para->children ?? [] as $text) {
                if (is_string($text->f['value'] ?? null)) {
                    $name .= $text->f['value'];
                }
            }
        }

        return $name;
    }
}
