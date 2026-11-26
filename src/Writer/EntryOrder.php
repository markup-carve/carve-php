<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * C2-W-158: the writer's entries on a supplied tree in document order of their paths (C2-L-011). A field of a node
 * stands before the nodes in its slots; entries the order does not decide keep the order in which they were found.
 */
final class EntryOrder
{
    /**
     * The slots of a node in source order: the title, then the order of the carve-js slot table.
     *
     * @var array<string, int>
     */
    private const SLOTS = [
        'title' => 0,
        'children' => 1,
        'caption' => 2,
        'columns' => 3,
        'short' => 4,
        'slots' => 5,
        'fallback' => 6,
        'base' => 7,
        'annotation' => 8,
        'longDescription' => 9,
        'pairs' => 10,
        'prefix' => 11,
        'locator' => 12,
        'suffix' => 13,
        'metadata' => 14,
    ];

    /**
     * @var array<string, int>
     */
    private const METADATA_SLOTS = [
        'title' => 0,
        'subtitle' => 1,
        'bibliography' => 2,
        'headers' => 3,
        'footers' => 4,
        'abstract' => 5,
        'fields' => 6,
    ];

    /**
     * @param array<mixed> $tree
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $entries
     *
     * @return array<\MarkupCarve\Carve\Diagnostics\Diagnostic>
     */
    public static function sort(array $tree, array $entries): array
    {
        if (count($entries) < 2) {
            return $entries;
        }
        $entries = array_values($entries);
        $keys = [];
        $ordered = true;
        foreach ($entries as $at => $entry) {
            $keys[$at] = self::key($tree, $entry->path ?? '');
            $ordered = $ordered && ($at === 0 || strcmp($keys[$at - 1], $keys[$at]) <= 0);
        }
        if ($ordered) {
            return $entries;
        }
        $found = array_keys($entries);
        array_multisort($keys, SORT_STRING, $found, SORT_NUMERIC, $entries);

        return $entries;
    }

    /**
     * Per node on the way down a 1, its slot and its index; a 0 when the rest names a field of the last node, which so
     * comes before the nodes in its slots. Packed big-endian, so a byte compare is the document order.
     *
     * @param array<mixed> $tree
     * @param string $path
     */
    private static function key(array $tree, string $path): string
    {
        $segments = $path === '' ? [] : explode('/', substr($path, 1));
        $count = count($segments);
        $key = [];
        $node = $tree;
        for ($at = 0; $at < $count;) {
            $step = self::step($tree, $node, $segments, $at);
            if ($step === null) {
                $key[] = 0;

                break;
            }
            [$node, $used, $parts] = $step;
            array_push($key, 1, ...$parts);
            $at += $used;
        }

        return pack('N*', ...$key);
    }

    /**
     * The node below `$node` that `$segments` name from `$at` on, or null when they name a field of `$node`.
     *
     * @param array<mixed> $tree
     * @param array<mixed> $node
     * @param array<string> $segments
     * @param int $at
     *
     * @return array{array<mixed>, int, array<int>}|null
     */
    private static function step(array $tree, array $node, array $segments, int $at): ?array
    {
        $slot = $segments[$at];
        $rank = self::SLOTS[$slot] ?? null;
        $value = $node[$slot] ?? null;
        if ($rank === null || !is_array($value)) {
            return null;
        }
        if ($slot === 'metadata') {
            return self::metadataStep($value, $segments, $at + 1, [$rank]);
        }
        if ($slot === 'slots') {
            $name = self::unescape($segments[$at + 1] ?? '');

            return self::nodeAt($value[$name] ?? null, $segments, $at + 2, 2, [$rank, self::slotRank($tree, $node, $value, $name)]);
        }
        if ($slot === 'locator') {
            return ($segments[$at + 1] ?? null) === 'children'
                ? self::nodeAt($value['children'] ?? null, $segments, $at + 2, 2, [$rank]) : null;
        }

        return self::nodeAt($value, $segments, $at + 1, 1, [$rank]);
    }

    /**
     * @param mixed $value A node or a list of nodes.
     * @param array<string> $segments
     * @param int $at The segment of the index in a list.
     * @param int $used
     * @param array<int> $parts
     *
     * @return array{array<mixed>, int, array<int>}|null
     */
    private static function nodeAt(mixed $value, array $segments, int $at, int $used, array $parts): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        if (is_string($value['type'] ?? null)) {
            return [$value, $used, [...$parts, 0]];
        }
        $token = $segments[$at] ?? null;
        if ($token === null || !ctype_digit($token)) {
            return null;
        }
        $node = $value[(int)$token] ?? null;

        return is_array($node) && is_string($node['type'] ?? null) ? [$node, $used + 1, [...$parts, (int)$token]] : null;
    }

    /**
     * @param array<mixed> $metadata
     * @param array<string> $segments
     * @param int $at The segment after `metadata`.
     * @param array<int> $parts
     *
     * @return array{array<mixed>, int, array<int>}|null
     */
    private static function metadataStep(array $metadata, array $segments, int $at, array $parts): ?array
    {
        $slot = $segments[$at] ?? '';
        $sub = self::METADATA_SLOTS[$slot] ?? null;
        $value = $metadata[$slot] ?? null;
        if ($sub === null || !is_array($value)) {
            return null;
        }
        $parts[] = $sub;
        if ($slot === 'fields') {
            return self::fieldStep($value, $segments, $at + 1, 2, $parts);
        }
        if ($slot === 'bibliography' || $slot === 'headers' || $slot === 'footers') {
            return self::partStep($slot, $value, $segments, $at + 1, $parts);
        }

        return self::nodeAt($value, $segments, $at + 1, 2, $parts);
    }

    /**
     * `bibliography/title`, `bibliography/containerTitle`, `headers/N/children` and `footers/N/children`.
     *
     * @param string $slot
     * @param array<mixed> $value
     * @param array<string> $segments
     * @param int $at The segment after the metadata slot.
     * @param array<int> $parts
     *
     * @return array{array<mixed>, int, array<int>}|null
     */
    private static function partStep(string $slot, array $value, array $segments, int $at, array $parts): ?array
    {
        $part = $segments[$at] ?? '';
        if ($slot === 'bibliography') {
            if ($part !== 'title' && $part !== 'containerTitle') {
                return null;
            }
            $parts[] = $part === 'title' ? 0 : 1;

            return self::nodeAt($value[$part] ?? null, $segments, $at + 1, 3, $parts);
        }
        $entry = ctype_digit($part) ? $value[(int)$part] ?? null : null;
        if (!is_array($entry) || ($segments[$at + 1] ?? null) !== 'children') {
            return null;
        }
        $parts[] = (int)$part;

        return self::nodeAt($entry['children'] ?? null, $segments, $at + 2, 4, $parts);
    }

    /**
     * Metadata fields by name in code point order, down to the nodes of an `inlines` or `blocks` value.
     *
     * @param array<mixed> $fields
     * @param array<string> $segments
     * @param int $at The segment of a field name.
     * @param int $used
     * @param array<int> $parts
     *
     * @return array{array<mixed>, int, array<int>}|null
     */
    private static function fieldStep(array $fields, array $segments, int $at, int $used, array $parts): ?array
    {
        $name = self::unescape($segments[$at] ?? '');
        $field = $fields[$name] ?? null;
        if (!is_array($field)) {
            return null;
        }
        $names = array_map('strval', array_keys($fields));
        sort($names, SORT_STRING);
        $parts[] = (int)array_search($name, $names, true);

        return self::valueStep($field, $segments, $at + 1, $used + 1, $parts);
    }

    /**
     * @param array<mixed> $field A metadata value: its `kind` and its `value`.
     * @param array<string> $segments
     * @param int $at The segment that must be `value`.
     * @param int $used
     * @param array<int> $parts
     *
     * @return array{array<mixed>, int, array<int>}|null
     */
    private static function valueStep(array $field, array $segments, int $at, int $used, array $parts): ?array
    {
        $value = $field['value'] ?? null;
        if (($segments[$at] ?? null) !== 'value' || !is_array($value)) {
            return null;
        }
        $kind = $field['kind'] ?? null;
        if ($kind === 'inlines' || $kind === 'blocks') {
            return self::nodeAt($value, $segments, $at + 1, $used + 1, $parts);
        }
        if ($kind === 'map') {
            return self::fieldStep($value, $segments, $at + 1, $used + 1, $parts);
        }
        $index = $segments[$at + 1] ?? '';
        $item = $kind === 'list' && ctype_digit($index) ? $value[(int)$index] ?? null : null;
        if (!is_array($item)) {
            return null;
        }
        $parts[] = (int)$index;

        return self::valueStep($item, $segments, $at + 2, $used + 2, $parts);
    }

    /**
     * C2-X-020: the named slots in the order of the declaration, the others after them in the order of the tree.
     *
     * @param array<mixed> $tree
     * @param array<mixed> $node
     * @param array<mixed> $slots
     * @param string $name
     */
    private static function slotRank(array $tree, array $node, array $slots, string $name): int
    {
        $declared = [];
        foreach ((array)($tree['extensions'] ?? []) as $declaration) {
            if (
                is_array($declaration) && is_array($declaration['slots'] ?? null)
                && ($declaration['name'] ?? null) === ($node['name'] ?? null)
            ) {
                $declared = $declaration['slots'];

                break;
            }
        }
        $at = array_search($name, $declared, true);
        if ($at !== false) {
            return (int)$at;
        }
        $at = array_search($name, array_map('strval', array_keys($slots)), true);

        return count($declared) + (int)$at;
    }

    private static function unescape(string $segment): string
    {
        return str_replace(['~1', '~0'], ['/', '~'], $segment);
    }
}
