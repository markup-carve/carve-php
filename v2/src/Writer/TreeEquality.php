<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Writer;

use MarkupCarve\Carve2\Generated\WriterTables;

/**
 * C2-A-161, C2-A-162: tree equality, which skips `pos`, `segments`, `srcByteLength` and the resolution fields of
 * `data/ast-results.json`. Text is coalesced (C2-A-040) before the comparison.
 */
final class TreeEquality
{
    /**
     * @param mixed $a
     * @param mixed $b
     *
     * @return bool
     */
    public static function equal(mixed $a, mixed $b): bool
    {
        return self::canonical($a) === self::canonical($b);
    }

    /**
     * The tree with the skipped fields removed and its keys sorted.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return self::coalesced(array_map(self::canonical(...), $value));
        }
        unset($value['pos'], $value['segments'], $value['srcByteLength']);
        $type = is_string($value['type'] ?? null) ? $value['type'] : '';
        $reference = isset($value['key']) && ($type === 'link' || $type === 'image');
        foreach (WriterTables::AST_RESULT_FIELDS[$type] ?? [] as $field => $when) {
            if ($when === '' || ($when === 'reference use' && $reference) || $when === 'derived from authored span mark') {
                unset($value[$field]);
            }
        }
        ksort($value, SORT_STRING);

        return array_map(self::canonical(...), $value);
    }

    /**
     * @param array<mixed> $nodes
     *
     * @return array<mixed>
     */
    private static function coalesced(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $at = count($out) - 1;
            if (
                is_array($node) && ($node['type'] ?? '') === 'text' && count($node) === 2 && $at >= 0 && is_array($out[$at])
                && ($out[$at]['type'] ?? '') === 'text' && count($out[$at]) === 2
            ) {
                $out[$at]['value'] = Inline::str($out[$at], 'value') . Inline::str($node, 'value');

                continue;
            }
            $out[] = $node;
        }

        return $out;
    }
}
