<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Layout;

use JsonException;
use MarkupCarve\Carve\Json\SchemaValidator;
use stdClass;

/**
 * The reader of a source-layout record: the schema (`data/layout-schema.json`), then the checks of C2-L-058: entry
 * order and paths (C2-L-011), ranges (C2-L-012), wraps (C2-L-020), runs (C2-L-027) and the encoding (C2-L-056).
 */
final class LayoutReader
{
    private static ?stdClass $schema = null;

    /**
     * @param string $json The record as JSON.
     * @param array<mixed>|null $tree The decoded tree the record belongs to; with it the slots of an extension node are
     *   ordered by its declaration (C2-X-020).
     *
     * @throws \MarkupCarve\Carve\Layout\LayoutError
     *
     * @return array<mixed>
     */
    public static function read(string $json, ?array $tree = null): array
    {
        try {
            $value = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new LayoutError('not-json', '');
        }
        $validator = new SchemaValidator(self::schema());
        if (!$value instanceof stdClass || !$validator->valid($value, self::schema(), '')) {
            throw new LayoutError('schema', '');
        }
        $length = self::inputLength($value);
        $order = new SlotOrder($tree);
        $previous = null;
        foreach ($value->nodes as $i => $entry) {
            $at = '/nodes/' . $i;
            self::entry($entry, $at, $length);
            $path = (string)$entry->path;
            if ($previous !== null && !$order->after($path, $previous)) {
                throw new LayoutError($path === $previous ? 'duplicate-path' : 'document-order', $at . '/path');
            }
            $previous = $path;
        }

        return (array)json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function schema(): stdClass
    {
        if (self::$schema === null) {
            $path = dirname(__DIR__, 2) . '/spec/data/layout-schema.json';
            $schema = json_decode((string)file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
            if (!$schema instanceof stdClass) {
                throw new LayoutError('schema', '');
            }
            self::$schema = $schema;
        }

        return self::$schema;
    }

    /**
     * C2-L-012, C2-L-056: the byte length of the input; `base64` holds bytes that are not well-formed UTF-8.
     *
     * @param \stdClass $record
     *
     * @throws \MarkupCarve\Carve\Layout\LayoutError
     *
     * @return int
     */
    private static function inputLength(stdClass $record): int
    {
        $source = (string)$record->source;
        if ($record->encoding === 'utf-8') {
            return strlen($source);
        }
        $bytes = base64_decode($source, true);
        if ($bytes === false) {
            throw new LayoutError('base64', '/source');
        }
        if (mb_check_encoding($bytes, 'UTF-8')) {
            throw new LayoutError('base64-of-utf8', '/encoding');
        }

        return strlen($bytes);
    }

    /**
     * @param \stdClass $entry
     * @param string $at
     * @param int $length
     *
     * @throws \MarkupCarve\Carve\Layout\LayoutError
     */
    private static function entry(stdClass $entry, string $at, int $length): void
    {
        if ($entry->startByte > $entry->endByte) {
            throw new LayoutError('range', $at . '/startByte');
        }
        if ($entry->endByte > $length) {
            throw new LayoutError('range', $at . '/endByte');
        }
        // C2-L-027
        if (isset($entry->openerRaw) !== isset($entry->closerRaw)) {
            throw new LayoutError('runs-pair', $at);
        }
        if (isset($entry->openerRaw) && $entry->openerRaw === '') {
            throw new LayoutError('opener-empty', $at . '/openerRaw');
        }
        // C2-L-011: the nodes in a fallback have no entry.
        if (str_contains($entry->path . '/', '/fallback/')) {
            throw new LayoutError('fallback-entry', $at . '/path');
        }
        // C2-L-020, C2-L-048: sorted by line, those of one line by row, those of one row by col.
        $last = [0, -1, -1];
        foreach ($entry->wraps ?? [] as $k => $wrap) {
            $key = [$wrap->line, $wrap->row ?? 0, $wrap->col ?? 0];
            if ($key < $last) {
                throw new LayoutError('wraps-order', $at . '/wraps/' . $k);
            }
            $last = $key;
        }
    }
}
