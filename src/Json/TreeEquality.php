<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Json;

use HashContext;
use MarkupCarve\Carve\Generated\Tables;

/**
 * C2-A-161, C2-A-162: whether two trees, as decoded JSON arrays, are the same document.
 */
final class TreeEquality
{
    /**
     * @var int
     */
    private const FEED_CHUNK = 65536;

    /**
     * `layout()` and the field names it was made for, by node type, the conditions `skipped()` reads and the names.
     *
     * @var array<string, array{string, array<string|int, string>, array<int, string|int>}>
     */
    private static array $layouts = [];

    /**
     * The buffer length at which `feed()` hands the bytes to the hash.
     */
    private static int $chunk = self::FEED_CHUNK;

    /**
     * @param mixed $a
     * @param mixed $b
     *
     * @return bool
     */
    public static function equal(mixed $a, mixed $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }

    /**
     * `equal()` without the normalized copies: both trees are walked in place and the walk stops at the first difference.
     *
     * @param mixed $a
     * @param mixed $b
     *
     * @return bool
     */
    public static function same(mixed $a, mixed $b): bool
    {
        return self::sameValues($a, $b, false, false);
    }

    /**
     * A key that two trees share exactly when `equal()` holds for them, so a tree can be dropped before the other is
     * built.
     *
     * @param mixed $value
     *
     * @return string
     */
    public static function fingerprint(mixed $value): string
    {
        $context = hash_init('xxh128');
        $buffer = '';
        self::feed($context, $buffer, $value, false);
        hash_update($context, $buffer);

        return hash_final($context);
    }

    /**
     * `fingerprint()` of a tree, with the fingerprint each node with content of its own would have alone, by its path
     * through `children`. The walk keeps paths down to the nodes of a type in `$stop`; below them it is `fingerprint()`'s.
     * A node whose children are all followed has no part.
     *
     * @param array<mixed> $tree A node with a type and content.
     * @param array<string, true> $stop
     *
     * @return array{string, array<string, string>}
     */
    public static function fingerprintParts(array $tree, array $stop): array
    {
        $type = $tree['type'] ?? null;
        if (!is_string($type)) {
            return [self::fingerprint($tree), []];
        }
        $context = hash_init('xxh128');
        $stream = '';
        $ranges = [];
        // The parts are hashed from the stream, so it is not handed on in pieces.
        self::$chunk = PHP_INT_MAX;
        try {
            self::feedParts($context, $stream, $tree, $type, false, '', $stop, $ranges);
        } finally {
            self::$chunk = self::FEED_CHUNK;
        }
        $parts = [];
        foreach ($ranges as $path => [$start, $end]) {
            $parts[$path] = hash('xxh128', substr($stream, $start, $end - $start));
        }

        return [hash('xxh128', $stream), $parts];
    }

    /**
     * @param mixed $a
     * @param mixed $b
     * @param bool $marksA `$a` stands in a table with span marks.
     * @param bool $marksB `$b` stands in a table with span marks.
     */
    private static function sameValues(mixed $a, mixed $b, bool $marksA, bool $marksB): bool
    {
        if (!is_array($a) || !is_array($b)) {
            return $a === $b;
        }
        $list = array_is_list($a);
        if ($list !== array_is_list($b)) {
            return self::normalize($a, $marksA) === self::normalize($b, $marksB);
        }

        return $list ? self::sameLists($a, $b, $marksA, $marksB) : self::sameMaps($a, $b, $marksA, $marksB);
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     * @param bool $marksA
     * @param bool $marksB
     */
    private static function sameLists(array $a, array $b, bool $marksA, bool $marksB): bool
    {
        if (self::hasTextPair($a) || self::hasTextPair($b)) {
            $a = self::coalesced($a);
            $b = self::coalesced($b);
        }
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $at => $item) {
            if (!self::sameValues($item, $b[$at], $marksA, $marksB)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     * @param bool $marksA
     * @param bool $marksB
     */
    private static function sameMaps(array $a, array $b, bool $marksA, bool $marksB): bool
    {
        $typeA = is_string($a['type'] ?? null) ? $a['type'] : null;
        $typeB = is_string($b['type'] ?? null) ? $b['type'] : null;
        if ($typeA === 'table') {
            $marksA = self::hasSpanMarks($a);
        }
        if ($typeB === 'table') {
            $marksB = self::hasSpanMarks($b);
        }
        $count = 0;
        foreach ($a as $name => $item) {
            if ($typeA !== null && self::skipped($a, $typeA, (string)$name, $marksA)) {
                continue;
            }
            if (
                !array_key_exists($name, $b) || ($typeB !== null && self::skipped($b, $typeB, (string)$name, $marksB))
                || !self::sameValues($item, $b[$name], $marksA, $marksB)
            ) {
                return false;
            }
            $count++;
        }
        foreach ($b as $name => $_) {
            if ($typeB === null || !self::skipped($b, $typeB, (string)$name, $marksB)) {
                $count--;
            }
        }

        return $count === 0;
    }

    /**
     * Hashes what `normalize()` returns for the value without building it. The bytes are those of `serialize()` for
     * scalars, gathered in `$buffer` and handed to the hash in large pieces.
     *
     * @param \HashContext $context
     * @param string $buffer
     * @param mixed $value
     * @param bool $spanMarks
     *
     * @return void
     */
    private static function feed(HashContext $context, string &$buffer, mixed $value, bool $spanMarks): void
    {
        if (strlen($buffer) > self::$chunk) {
            hash_update($context, $buffer);
            $buffer = '';
        }
        if (!is_array($value)) {
            $buffer .= is_string($value) ? 's:' . strlen($value) . ':"' . $value . '";' : serialize($value);

            return;
        }
        if (array_is_list($value)) {
            $items = self::hasTextPair($value) ? self::coalesced($value) : $value;
            $buffer .= 'l' . count($items) . ':';
            foreach ($items as $item) {
                if (is_string($item)) {
                    $buffer .= 's:' . strlen($item) . ':"' . $item . '";';
                } else {
                    self::feed($context, $buffer, $item, $spanMarks);
                }
            }

            return;
        }
        $type = is_string($value['type'] ?? null) ? $value['type'] : null;
        if ($type === 'table') {
            $spanMarks = self::hasSpanMarks($value);
        }
        [$head, $names] = self::fields($value, $type, $spanMarks);
        $buffer .= $head;
        foreach ($names as $name => $spelled) {
            $buffer .= $spelled;
            $item = $value[$name];
            if (is_string($item)) {
                $buffer .= 's:' . strlen($item) . ':"' . $item . '";';
            } else {
                self::feed($context, $buffer, $item, $spanMarks);
            }
        }
    }

    /**
     * `fingerprintParts()` below the root: the node at `$path`, a map with a type and content.
     *
     * @param \HashContext $context
     * @param string $stream
     * @param array<mixed> $value
     * @param string $type
     * @param bool $spanMarks
     * @param string $path
     * @param array<string, true> $stop
     * @param array<string, array{int, int}> $ranges
     */
    private static function feedParts(
        HashContext $context,
        string &$stream,
        array $value,
        string $type,
        bool $spanMarks,
        string $path,
        array $stop,
        array &$ranges,
    ): void {
        $start = strlen($stream);
        // In a table with span marks the node is spelled otherwise than alone.
        $alone = !$spanMarks;
        // Only a node with content of its own gets a part, so nested blocks do not hash the same bytes again.
        $own = false;
        if ($type === 'table') {
            $spanMarks = self::hasSpanMarks($value);
        }
        [$head, $names] = self::fields($value, $type, $spanMarks);
        $stream .= $head;
        foreach ($names as $name => $spelled) {
            $stream .= $spelled;
            $item = $value[$name];
            if ($name !== 'children' || !is_array($item) || !array_is_list($item) || self::hasTextPair($item)) {
                $own = $own || $name === 'children';
                if (is_string($item)) {
                    $stream .= 's:' . strlen($item) . ':"' . $item . '";';
                } else {
                    self::feed($context, $stream, $item, $spanMarks);
                }

                continue;
            }
            $stream .= 'l' . count($item) . ':';
            foreach ($item as $k => $child) {
                $childType = is_array($child) ? $child['type'] ?? null : null;
                if (is_array($child) && is_string($childType) && !isset($stop[$childType]) && is_array($child['children'] ?? null)) {
                    self::feedParts($context, $stream, $child, $childType, $spanMarks, $path . '/children/' . $k, $stop, $ranges);
                } elseif (is_string($child)) {
                    $own = true;
                    $stream .= 's:' . strlen($child) . ':"' . $child . '";';
                } else {
                    $own = true;
                    self::feed($context, $stream, $child, $spanMarks);
                }
            }
        }
        if ($alone && $own) {
            $ranges[$path] = [$start, strlen($stream)];
        }
    }

    /**
     * The serialized count and fields of a map, kept by node type, the conditions `skipped()` reads and the names.
     *
     * @param array<mixed> $value
     * @param string|null $type
     * @param bool $spanMarks
     *
     * @return array{string, array<string|int, string>}
     */
    private static function fields(array $value, ?string $type, bool $spanMarks): array
    {
        if ($type === null) {
            return self::layout($value, null, $spanMarks);
        }
        $keys = array_keys($value);
        $shape = $type . ($spanMarks ? "\1" : "\2") . (isset($value['key']) ? "\1" : "\2") . implode("\0", $keys);
        $kept = self::$layouts[$shape] ?? null;
        // A key may hold the separator, so the kept layout is used only for the same keys.
        if ($kept === null || $kept[2] !== $keys) {
            $kept = self::$layouts[$shape] = [...self::layout($value, $type, $spanMarks), $keys];
        }

        return [$kept[0], $kept[1]];
    }

    /**
     * The fields of a node that `normalize()` keeps, in its order, each with its serialized name, behind the count.
     *
     * @param array<mixed> $value
     * @param string|null $type
     * @param bool $spanMarks
     *
     * @return array{string, array<string|int, string>}
     */
    private static function layout(array $value, ?string $type, bool $spanMarks): array
    {
        $names = [];
        foreach ($value as $name => $_) {
            if ($type === null || !self::skipped($value, $type, (string)$name, $spanMarks)) {
                $names[$name] = is_string($name) ? 's:' . strlen($name) . ':"' . $name . '";' : 'i:' . $name . ';';
            }
        }
        ksort($names);

        return ['m' . count($names) . ':', $names];
    }

    /**
     * Whether two adjacent items of the list are text nodes, which `coalesced()` joins.
     *
     * @param array<mixed> $list
     */
    private static function hasTextPair(array $list): bool
    {
        $text = false;
        foreach ($list as $item) {
            $isText = is_array($item) && ($item['type'] ?? null) === 'text';
            if ($isText && $text) {
                return true;
            }
            $text = $isText;
        }

        return false;
    }

    /**
     * The tree with every field equality skips removed, text coalesced and object keys sorted.
     *
     * @param mixed $value
     * @param bool $spanMarks The node stands in a table with span marks.
     *
     * @return mixed
     */
    public static function normalize(mixed $value, bool $spanMarks = false): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::normalize($item, $spanMarks), self::coalesced($value));
        }
        $type = is_string($value['type'] ?? null) ? $value['type'] : null;
        if ($type === 'table') {
            $spanMarks = self::hasSpanMarks($value);
        }
        $out = [];
        foreach ($value as $name => $item) {
            if ($type !== null && self::skipped($value, $type, (string)$name, $spanMarks)) {
                continue;
            }
            $out[$name] = self::normalize($item, $spanMarks);
        }
        ksort($out);

        return $out;
    }

    /**
     * @param array<mixed> $node
     * @param string $type
     * @param string $name
     * @param bool $spanMarks
     *
     * @return bool
     */
    private static function skipped(array $node, string $type, string $name, bool $spanMarks): bool
    {
        if ($name === 'pos' || $name === 'segments' || $name === 'srcByteLength') {
            return true;
        }
        $when = Tables::AST_RESULTS[$type][$name] ?? null;

        return match ($when) {
            null => false,
            'reference use' => isset($node['key']),
            'derived from authored span mark' => $spanMarks,
            default => true,
        };
    }

    /**
     * C2-A-040: adjacent text nodes compare as one.
     *
     * @param array<mixed> $list
     *
     * @return array<mixed>
     */
    private static function coalesced(array $list): array
    {
        $out = [];
        $last = -1;
        foreach ($list as $item) {
            if (
                $last >= 0 && is_array($item) && ($item['type'] ?? null) === 'text'
                && is_array($out[$last]) && ($out[$last]['type'] ?? null) === 'text'
            ) {
                $out[$last]['value'] = self::str($out[$last]['value'] ?? '') . self::str($item['value'] ?? '');

                continue;
            }
            $out[] = $item;
            $last++;
        }

        return $out;
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * C2-A-093: a cell of the rows of the table carries a span mark.
     *
     * @param array<mixed> $table
     *
     * @return bool
     */
    private static function hasSpanMarks(array $table): bool
    {
        foreach (self::listOf($table) as $group) {
            foreach (self::listOf($group) as $row) {
                foreach (self::listOf($row) as $cell) {
                    if (is_array($cell) && ($cell['type'] ?? null) === 'table_cell' && isset($cell['span'])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param mixed $node
     *
     * @return array<mixed>
     */
    private static function listOf(mixed $node): array
    {
        return is_array($node) && is_array($node['children'] ?? null) ? $node['children'] : [];
    }
}
