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
        if (!is_array($value)) {
            $buffer .= is_string($value) ? 's:' . strlen($value) . ':"' . $value . '";' : serialize($value);
            if (strlen($buffer) > self::FEED_CHUNK) {
                hash_update($context, $buffer);
                $buffer = '';
            }

            return;
        }
        if (array_is_list($value)) {
            $items = self::coalesced($value);
            $buffer .= 'l' . count($items) . ':';
            foreach ($items as $item) {
                self::feed($context, $buffer, $item, $spanMarks);
            }

            return;
        }
        $type = is_string($value['type'] ?? null) ? $value['type'] : null;
        if ($type === 'table') {
            $spanMarks = self::hasSpanMarks($value);
        }
        $names = [];
        foreach ($value as $name => $_) {
            if ($type === null || !self::skipped($value, $type, (string)$name, $spanMarks)) {
                $names[$name] = true;
            }
        }
        ksort($names);
        $buffer .= 'm' . count($names) . ':';
        foreach ($names as $name => $_) {
            $buffer .= is_string($name) ? 's:' . strlen($name) . ':"' . $name . '";' : 'i:' . $name . ';';
            self::feed($context, $buffer, $value[$name], $spanMarks);
        }
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
