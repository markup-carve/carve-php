<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use InvalidArgumentException;
use stdClass;

final class AnnotationRanges
{
    /**
     * @param array<string, mixed> $ast
     * @param list<array<string, mixed>> $ranges
     *
     * @return array{version: 1, ranges: list<array<string, mixed>>}
     */
    public static function create(array $ast, array $ranges): array
    {
        foreach ($ranges as &$range) {
            if (($range['data'] ?? null) === []) {
                $range['data'] = new stdClass();
            }
        }
        unset($range);
        $sidecar = ['version' => 1, 'ranges' => $ranges];
        self::read($ast, $sidecar);

        return $sidecar;
    }

    /**
     * @param array<string, mixed> $ast
     * @param array<string, mixed> $sidecar
     *
     * @throws \InvalidArgumentException
     */
    public static function read(array $ast, array $sidecar): void
    {
        SidecarPath::fields($sidecar, ['version', 'ranges'], ['version', 'ranges']);
        if ($sidecar['version'] !== 1 || !is_array($sidecar['ranges']) || !array_is_list($sidecar['ranges'])) {
            throw new InvalidArgumentException('Unsupported or malformed annotation range sidecar.');
        }
        $ids = [];
        $cursor = 0;
        $starts = $lengths = [];
        self::walkText($ast, '', $cursor, $starts, $lengths);
        foreach ($sidecar['ranges'] as $range) {
            if (!is_array($range)) {
                throw new InvalidArgumentException('Invalid annotation range.');
            }
            SidecarPath::fields($range, ['id', 'kind', 'start', 'end'], ['id', 'kind', 'start', 'end', 'data']);
            $id = $range['id'];
            if (!is_string($id) || $id === '' || !SidecarPath::nonempty($range['kind']) || isset($ids[$id])) {
                throw new InvalidArgumentException('Invalid or duplicate annotation id or kind.');
            }
            if (array_key_exists('data', $range) && !$range['data'] instanceof stdClass && (!is_array($range['data']) || array_is_list($range['data']) && $range['data'] !== [])) {
                throw new InvalidArgumentException('Annotation data must be an object.');
            }
            $start = self::anchor($range['start'], $starts, $lengths);
            $end = self::anchor($range['end'], $starts, $lengths);
            if ($start > $end) {
                throw new InvalidArgumentException('Annotation range ends before it starts.');
            }
            $ids[$id] = true;
        }
    }

    /**
     * @param mixed $anchor
     * @param array<string, int> $starts
     * @param array<string, int> $lengths
     *
     * @throws \InvalidArgumentException
     */
    private static function anchor(mixed $anchor, array $starts, array $lengths): int
    {
        if (!is_array($anchor)) {
            throw new InvalidArgumentException('Annotation anchor must be an object.');
        }
        SidecarPath::fields($anchor, ['path', 'offset'], ['path', 'offset']);
        if (!is_string($anchor['path']) || !is_int($anchor['offset']) || $anchor['offset'] < 0) {
            throw new InvalidArgumentException('Annotation anchor requires a path and nonnegative offset.');
        }
        if (!isset($starts[$anchor['path']])) {
            throw new InvalidArgumentException('Annotation path does not address a node.');
        }
        if ($anchor['offset'] > $lengths[$anchor['path']]) {
            throw new InvalidArgumentException('Annotation offset exceeds node text.');
        }

        return $starts[$anchor['path']] + $anchor['offset'];
    }

    /**
     * @param mixed $value
     * @param string $path
     * @param int $cursor
     * @param array<string, int> $starts
     * @param array<string, int> $lengths
     */
    private static function walkText(mixed $value, string $path, int &$cursor, array &$starts, array &$lengths): void
    {
        if (!is_array($value)) {
            return;
        }
        $isNode = is_string($value['type'] ?? null);
        if ($isNode) {
            $starts[$path] = $cursor;
            $text = $value['value'] ?? $value['text'] ?? $value['content'] ?? null;
            if (is_string($text)) {
                $lengths[$path] = count(preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
                $cursor += $lengths[$path];

                return;
            }
        }
        foreach ($value as $key => $child) {
            if ($key === 'attrs' || $key === 'pos' || $key === 'keyValues' || !is_array($child)) {
                continue;
            }
            self::walkText($child, $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string)$key), $cursor, $starts, $lengths);
        }
        if ($isNode) {
            $lengths[$path] = $cursor - $starts[$path];
        }
    }
}
