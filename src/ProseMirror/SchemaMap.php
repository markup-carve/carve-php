<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\ProseMirror;

use RuntimeException;

/**
 * The Carve-to-ProseMirror vocabulary, read from the published map.
 *
 * The map is owned by markup-carve/carve-grammars, which defines the CarveKit
 * schema and the serializer; a copy is vendored in `resources/` with its source
 * commit. Restating the mapping here instead would drift exactly the way the
 * spec's node-vocabulary test exists to prevent - carve-php once emitted
 * `citation-group` while every other implementation used underscores.
 *
 * Refreshing it is a copy plus a commit-id bump. ProseMirrorSchemaMapTest keeps
 * the copy usable: every node type this engine can produce must have a
 * mapped-or-unmapped decision in it.
 */
final class SchemaMap
{
    /**
     * @var array{types: array<string, array{kind: string, pm: string|array<string>, notes?: string}>, unmapped: array<string, string>}|null
     */
    private static ?array $data = null;

    /**
     * The ProseMirror node or mark name for a Carve node type, or null when the
     * editor model has none.
     */
    public static function nameFor(string $carveType): ?string
    {
        $entry = self::data()['types'][$carveType] ?? null;
        if ($entry === null) {
            return null;
        }

        $pm = $entry['pm'];

        // A Carve type can span several ProseMirror names (a list is bullet,
        // ordered or task); the first is the default and the caller narrows it
        // from the node's own state.
        return is_array($pm) ? ($pm[0] ?? null) : $pm;
    }

    /**
     * Every ProseMirror name a Carve type may map to.
     *
     * @return array<string>
     */
    public static function namesFor(string $carveType): array
    {
        $entry = self::data()['types'][$carveType] ?? null;
        if ($entry === null) {
            return [];
        }

        return is_array($entry['pm']) ? $entry['pm'] : [$entry['pm']];
    }

    public static function isMark(string $carveType): bool
    {
        return (self::data()['types'][$carveType]['kind'] ?? null) === 'mark';
    }

    /**
     * Why the editor model cannot represent this type, or null when it can.
     */
    public static function unmappedReason(string $carveType): ?string
    {
        return self::data()['unmapped'][$carveType] ?? null;
    }

    /**
     * The Carve type a ProseMirror name maps back to, or null when the name is
     * not part of the published vocabulary.
     */
    public static function carveTypeFor(string $proseMirrorName): ?string
    {
        foreach (self::data()['types'] as $carveType => $entry) {
            $names = is_array($entry['pm']) ? $entry['pm'] : [$entry['pm']];
            if (in_array($proseMirrorName, $names, true)) {
                return $carveType;
            }
        }

        return null;
    }

    /**
     * @return array<string, string> type => reason
     */
    public static function unmapped(): array
    {
        return self::data()['unmapped'];
    }

    /**
     * @return array<string>
     */
    public static function mappedTypes(): array
    {
        return array_keys(self::data()['types']);
    }

    /**
     * @throws \RuntimeException
     *
     * @return array{types: array<string, array{kind: string, pm: string|array<string>, notes?: string}>, unmapped: array<string, string>}
     */
    private static function data(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $path = dirname(__DIR__, 2) . '/resources/prosemirror-schema-map.json';
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Cannot read the ProseMirror schema map at %s', $path));
        }

        /** @var array{types: array<string, array{kind: string, pm: string|array<string>, notes?: string}>, unmapped: array<string, string>} $data */
        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::$data = $data;

        return $data;
    }
}
