<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

/**
 * Typed reads of a node in JSON form, as the importer builds it.
 */
final class J
{
    /**
     * @param array<string, mixed> $node
     * @param string $key
     *
     * @return string
     */
    public static function str(array $node, string $key): string
    {
        $value = $node[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $node
     * @param string $key
     *
     * @return int|null
     */
    public static function int(array $node, string $key): ?int
    {
        $value = $node[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * The nodes of a slot.
     *
     * @param array<string, mixed> $node
     * @param string $key
     *
     * @return array<array<string, mixed>>
     */
    public static function nodes(array $node, string $key = 'children'): array
    {
        $value = $node[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $node */
                $node = $item;
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * The `attrs` object of a node.
     *
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    public static function attrs(array $node): array
    {
        $value = $node['attrs'] ?? [];
        /** @var array<string, mixed> $out */
        $out = is_array($value) ? $value : [];

        return $out;
    }

    /**
     * @param array<string, mixed> $attrs
     *
     * @return array<string>
     */
    public static function classes(array $attrs): array
    {
        $value = $attrs['classes'] ?? [];

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * @param array<string, mixed> $attrs
     *
     * @return array<array{0: string, 1: string}>
     */
    public static function pairs(array $attrs): array
    {
        $value = $attrs['keyValues'] ?? [];
        $out = [];
        foreach (is_array($value) ? $value : [] as $pair) {
            if (is_array($pair) && is_string($pair[0] ?? null) && is_string($pair[1] ?? null)) {
                $out[] = [$pair[0], $pair[1]];
            }
        }

        return $out;
    }
}
