<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * C2-W-151: spaces and tabs that would end a source line in text are insignificant. The tree without them is what a
 * write is compared with: a parse never gives them. Mirrors carve-js `write-trim.ts`.
 */
final class LineEndBlanks
{
    /**
     * The block nodes whose inline content ends at a line end.
     *
     * @var array<string, true>
     */
    private const LINE_HOLDERS = [
        'paragraph' => true,
        'heading' => true,
        'definition_term' => true,
        'cell_paragraph' => true,
        'line' => true,
        'citation_definition' => true,
    ];

    /**
     * The fields that keep a text node as it is: its attribute block follows it, so its spaces end no line.
     *
     * @var array<string>
     */
    private const KEEPING_FIELDS = ['attrs', 'data', 'lang', 'dir', 'metadata'];

    /**
     * Drops the spaces and tabs at the end of each text node that stands before a soft break or at the end of the
     * content of a line holder, and the text nodes that hold nothing else. A table the writer writes delimited keeps
     * its cells as they are (C2-W-066), as does one that trimming would turn delimited.
     *
     * @param array<string, mixed> $tree
     * @param array<string, mixed> $formats The recorded `sourceFormat` of a table by path.
     * @param array<string, array<int>> $removed The indexes of the dropped texts, by the path of their list.
     *
     * @return array<string, mixed>
     */
    public static function trimmed(array $tree, array $formats = [], array &$removed = []): array
    {
        $removed = [];

        return self::node($tree, '', $formats, $removed);
    }

    /**
     * The layout record with each node path moved past the dropped texts before it; an entry of a dropped text goes.
     *
     * @param array<mixed>|null $layout
     * @param array<string, array<int>> $removed
     *
     * @return array<mixed>|null
     */
    public static function remapLayout(?array $layout, array $removed): ?array
    {
        if ($layout === null || $removed === [] || !is_array($layout['nodes'] ?? null)) {
            return $layout;
        }
        $nodes = [];
        foreach ($layout['nodes'] as $entry) {
            if (is_array($entry) && is_string($entry['path'] ?? null)) {
                $path = self::remapPath($entry['path'], $removed);
                if ($path === null) {
                    continue;
                }
                $entry['path'] = $path;
            }
            $nodes[] = $entry;
        }
        $layout['nodes'] = $nodes;

        return $layout;
    }

    /**
     * @param string $path
     * @param array<string, array<int>> $removed
     *
     * @return string|null
     */
    private static function remapPath(string $path, array $removed): ?string
    {
        $original = '';
        $moved = '';
        foreach ($path === '' ? [] : explode('/', substr($path, 1)) as $step) {
            $gone = $removed[$original] ?? null;
            $original .= '/' . $step;
            if ($gone !== null && ctype_digit($step)) {
                $index = (int)$step;
                if (in_array($index, $gone, true)) {
                    return null;
                }
                $step = (string)($index - count(array_filter($gone, static fn (int $at): bool => $at < $index)));
            }
            $moved .= '/' . $step;
        }

        return $moved;
    }

    /**
     * @param array<mixed> $node
     * @param string $path
     * @param array<string, mixed> $formats
     * @param array<string, array<int>> $removed
     *
     * @return array<mixed>
     */
    private static function node(array $node, string $path, array $formats, array &$removed): array
    {
        $type = $node['type'] ?? null;
        if ($type === 'text') {
            return $node;
        }
        $holder = is_string($type) && isset(self::LINE_HOLDERS[$type]);
        foreach ($node as $key => $value) {
            if ($key === 'type' || !is_array($value) || $value === []) {
                continue;
            }
            $slot = $path . '/' . $key;
            if (is_string($value['type'] ?? null)) {
                $node[$key] = self::node($value, $slot, $formats, $removed);

                continue;
            }
            if (!array_is_list($value) || !is_array($value[0]) || !is_string($value[0]['type'] ?? null)) {
                continue;
            }
            if ($type === 'table' && $key === 'children') {
                $node[$key] = self::cells($node, $value, $slot, $path, $formats, $removed);

                continue;
            }
            $node[$key] = self::list($value, $slot, $holder && $key === 'children', $formats, $removed);
        }

        return $node;
    }

    /**
     * @param array<mixed> $table
     * @param array<mixed> $groups
     * @param string $slot
     * @param string $path
     * @param array<string, mixed> $formats
     * @param array<string, array<int>> $removed
     *
     * @return array<mixed>
     */
    private static function cells(array $table, array $groups, string $slot, string $path, array $formats, array &$removed): array
    {
        $format = $formats[$path] ?? null;
        $spans = SpanMarks::of($table, $path) !== null;
        if (TableWriter::writesDelimited($table, $format, $spans)) {
            return $groups;
        }
        $cells = [];
        $trimmed = self::list($groups, $slot, false, $formats, $cells);
        $table['children'] = $trimmed;
        if (TableWriter::writesDelimited($table, $format, $spans)) {
            return $groups;
        }
        $removed += $cells;

        return $trimmed;
    }

    /**
     * Right to left, so a text before a dropped text sees what follows it then.
     *
     * @param array<mixed> $nodes
     * @param string $slot
     * @param bool $atLineEnd
     * @param array<string, mixed> $formats
     * @param array<string, array<int>> $removed
     *
     * @return array<mixed>
     */
    private static function list(array $nodes, string $slot, bool $atLineEnd, array $formats, array &$removed): array
    {
        $next = null;
        $dropped = [];
        for ($i = count($nodes) - 1; $i >= 0; $i--) {
            $child = $nodes[$i];
            $after = $next;
            $next = $child;
            if (!is_array($child)) {
                continue;
            }
            if (($child['type'] ?? null) !== 'text') {
                $nodes[$i] = self::node($child, $slot . '/' . $i, $formats, $removed);

                continue;
            }
            if (array_intersect_key($child, array_flip(self::KEEPING_FIELDS)) !== []) {
                continue;
            }
            $endsLine = $after === null ? $atLineEnd : is_array($after) && ($after['type'] ?? null) === 'soft_break';
            if (!$endsLine) {
                continue;
            }
            $value = is_string($child['value'] ?? null) ? $child['value'] : '';
            $kept = rtrim($value, " \t");
            if ($kept === $value) {
                continue;
            }
            if ($kept !== '') {
                $child['value'] = $kept;
                $nodes[$i] = $child;

                continue;
            }
            $next = $after;
            unset($nodes[$i]);
            $dropped[] = $i;
        }
        if ($dropped === []) {
            return $nodes;
        }
        $removed[$slot] = $dropped;

        return array_values($nodes);
    }
}
