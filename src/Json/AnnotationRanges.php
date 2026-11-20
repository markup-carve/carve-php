<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Json;

use JsonException;
use stdClass;

// The schema, pointer, slot and byte-boundary checks form one reader gate.
// phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded
final class AnnotationRanges
{
    private static ?stdClass $schema = null;

    /**
     * @var array<string, array<string>>|null
     */
    private static ?array $slots = null;

    /**
     * @param \stdClass $root
     * @param mixed $sidecar
     * @param array<string, \stdClass> $declared
     */
    public static function check(stdClass $root, mixed $sidecar, array $declared): void
    {
        try {
            $value = json_decode(json_encode($sidecar, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new TreeError('annotation-range-schema', '');
        }
        $schemaText = (string)file_get_contents(dirname(__DIR__, 2) . '/spec/data/annotation-ranges.schema.json');
        $schema = self::$schema ?? json_decode($schemaText, false, 512, JSON_THROW_ON_ERROR);
        if (!$schema instanceof stdClass) {
            throw new TreeError('annotation-range-schema', '');
        }
        self::$schema = $schema;
        $validator = new SchemaValidator($schema);
        if (!$validator->valid($value, $schema)) {
            $at = explode(': ', $validator->errors[0] ?? '$', 2)[0];
            $at = preg_replace_callback(
                '/\[([0-9]+)\]|\.([^\.\[]+)/',
                static fn (array $m): string => '/' . str_replace(['~', '/'], ['~0', '~1'], $m[1] !== '' ? $m[1] : $m[2]),
                $at,
            );

            throw new TreeError('annotation-range-schema', substr((string)$at, 1));
        }
        if (!$value instanceof stdClass) {
            throw new TreeError('annotation-range-schema', '');
        }

        $ranges = [];
        foreach ($value->ranges as $index => $range) {
            if (isset($ranges[$range->id])) {
                throw new TreeError('annotation-range-id', '/ranges/' . $index . '/id');
            }
            $ranges[$range->id] = $range;
            foreach (['start', 'end'] as $side) {
                $endpoint = $range->{$side};
                $at = '/ranges/' . $index . '/' . $side;
                $node = self::nodeAt($root, $endpoint->path);
                if ($node === null) {
                    throw new TreeError('annotation-range-path', $at . '/path');
                }
                $text = self::annotationText($node, $declared);
                $boundaries = [0 => true];
                $bytes = 0;
                foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
                    $bytes += strlen($char);
                    $boundaries[$bytes] = true;
                }
                if (!isset($boundaries[$endpoint->offset])) {
                    throw new TreeError('annotation-range-offset', $at . '/offset');
                }
            }
        }
        $pending = [[$root, '']];
        while ($pending !== []) {
            [$item, $path] = array_pop($pending);
            if (is_array($item)) {
                foreach ($item as $index => $child) {
                    $pending[] = [$child, $path . '/' . $index];
                }

                continue;
            }
            if (!$item instanceof stdClass) {
                continue;
            }
            if (isset($item->type) && is_string($item->type)) {
                foreach ($item->data->{'carve.annotation'}->ids ?? [] as $id) {
                    $range = $ranges[$id] ?? null;
                    if ($range === null || !self::under($range->start->path, $path) && !self::under($range->end->path, $path)) {
                        throw new TreeError('annotation-range-binding', $path . '/data/carve.annotation');
                    }
                }
            }
            foreach (get_object_vars($item) as $name => $child) {
                if (in_array($name, ['data', 'props', 'payload', 'source', 'resources', 'extensions'], true)) {
                    continue;
                }
                if ($child instanceof stdClass || is_array($child)) {
                    $pending[] = [$child, $path . '/' . str_replace(['~', '/'], ['~0', '~1'], $name)];
                }
            }
        }
    }

    private static function under(string $endpoint, string $node): bool
    {
        return $endpoint === $node || str_starts_with($endpoint, $node . '/');
    }

    private static function nodeAt(stdClass $root, string $pointer): ?stdClass
    {
        $at = $root;
        foreach (explode('/', ltrim($pointer, '/')) as $token) {
            if ($pointer === '') {
                break;
            }
            $key = str_replace(['~1', '~0'], ['/', '~'], $token);
            $at = $at instanceof stdClass ? ($at->{$key} ?? null) : (is_array($at) ? ($at[$key] ?? null) : null);
        }

        return $at instanceof stdClass && isset($at->type) ? $at : null;
    }

    /**
     * @param \stdClass $node
     * @param array<string, \stdClass> $declared
     *
     * @throws \MarkupCarve\Carve\Json\TreeError
     */
    private static function annotationText(stdClass $node, array $declared): string
    {
        $slotText = (string)file_get_contents(dirname(__DIR__, 2) . '/spec/data/node-slots.json');
        if (self::$slots === null) {
            $decoded = json_decode($slotText, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || !isset($decoded['nodes']) || !is_array($decoded['nodes'])) {
                throw new TreeError('annotation-slots', '');
            }
            $slots = [];
            foreach ($decoded['nodes'] as $name => $entries) {
                if (!is_string($name) || !is_array($entries) || !array_is_list($entries)) {
                    throw new TreeError('annotation-slots', '');
                }
                foreach ($entries as $entry) {
                    if (!is_string($entry)) {
                        throw new TreeError('annotation-slots', '');
                    }
                }
                $slots[$name] = $entries;
            }
            self::$slots = $slots;
        }
        $type = $node->type;
        $text = match ($type) {
            'soft_break', 'hard_break' => "\n",
            'non_breaking_space' => "\u{00A0}",
            'smart_punctuation' => $node->source,
            default => $node->value ?? $node->alt ?? '',
        };
        foreach (self::$slots[$type] ?? [] as $slot) {
            $value = $node;
            foreach (explode('.', $slot) as $key) {
                if ($key === '*') {
                    $names = $declared[$node->name ?? '']->slots ?? [];
                    $value = array_map(static fn (string $name): mixed => $value->{$name} ?? null, $names);
                } else {
                    $value = $value instanceof stdClass ? ($value->{$key} ?? null) : null;
                }
            }
            $text .= self::childText($value, $declared);
        }

        return $text;
    }

    /**
     * @param mixed $value
     * @param array<string, \stdClass> $declared
     */
    private static function childText(mixed $value, array $declared): string
    {
        if (is_array($value)) {
            return implode('', array_map(static fn (mixed $child): string => self::childText($child, $declared), $value));
        }
        if (!$value instanceof stdClass) {
            return '';
        }
        if (isset($value->type)) {
            return self::annotationText($value, $declared);
        }

        return implode('', array_map(static fn (mixed $child): string => self::childText($child, $declared), get_object_vars($value)));
    }
}
// phpcs:enable Generic.Metrics.CyclomaticComplexity.MaxExceeded
