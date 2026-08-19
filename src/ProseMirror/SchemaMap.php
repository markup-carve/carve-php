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
     * Wire types the published map deliberately does not name.
     *
     * `tag` is a real PART 12 type this engine produces, but the spec
     * classifies it under `mention` for profile purposes - profiles.md says the
     * vocabulary "does not list" it - so carve-grammars has no `tag` key and
     * should not grow one. Restating it as a local entry is how this copy stops
     * being a copy, and the entry that was here did nothing anyway: the
     * renderer asks by node type and a Mention answers `mention` whatever its
     * flavor, so nothing ever read it. A dead entry that satisfies the
     * has-a-decision test is a check that cannot fail.
     *
     * An alias resolves through the entry that owns the name instead. The
     * ProseMirror name is spelled out because it selects the flavor: `mention`
     * maps to both, and which one applies is the whole question here.
     *
     * @var array<string, array{through: string, pm: string}>
     */
    private const ALIASES = [
        'tag' => ['through' => 'mention', 'pm' => 'carveTag'],
    ];

    /**
     * @var array{types: array<string, array{kind: string, pm: string|array<string>, accepts?: array<string>, notes?: string}>, unmapped: array<string, string>, preservationNodes?: array<string, mixed>, markCarrierNodes?: array<string, mixed>}|null
     */
    private static ?array $data = null;

    /**
     * The ProseMirror node or mark name for a Carve node type, or null when the
     * editor model has none.
     */
    public static function nameFor(string $carveType): ?string
    {
        $alias = self::aliasName($carveType);
        if ($alias !== null) {
            return $alias;
        }

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
        $alias = self::aliasName($carveType);
        if ($alias !== null) {
            return [$alias];
        }

        $entry = self::data()['types'][$carveType] ?? null;
        if ($entry === null) {
            return [];
        }

        return is_array($entry['pm']) ? $entry['pm'] : [$entry['pm']];
    }

    public static function isMark(string $carveType): bool
    {
        $carveType = self::ALIASES[$carveType]['through'] ?? $carveType;

        return (self::data()['types'][$carveType]['kind'] ?? null) === 'mark';
    }

    /**
     * The ProseMirror name an aliased wire type takes, or null when the type is
     * not aliased.
     *
     * The name is only accepted when the entry it resolves through still lists
     * it. Upstream renaming `carveTag`, or dropping it from the `mention`
     * entry, must surface as a type with no decision - which the corpus test
     * reports - rather than as this engine emitting a name CarveKit no longer
     * registers.
     */
    private static function aliasName(string $carveType): ?string
    {
        $alias = self::ALIASES[$carveType] ?? null;
        if ($alias === null) {
            return null;
        }

        $entry = self::data()['types'][$alias['through']] ?? null;
        if ($entry === null) {
            return null;
        }

        $names = is_array($entry['pm']) ? $entry['pm'] : [$entry['pm']];

        return in_array($alias['pm'], $names, true) ? $alias['pm'] : null;
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
     *
     * This reads `accepts` as well as `pm`, and it is the only method that
     * does. The two fields are not interchangeable: `pm` is what CarveKit
     * registers and therefore what this engine may emit, while `accepts` names
     * a stock Tiptap extension spelling for a concept Carve already models
     * (`mention` for tiptap/extension-mention). Widening the outbound side too
     * would make the engine emit a name the editor's schema never defined.
     */
    public static function carveTypeFor(string $proseMirrorName): ?string
    {
        foreach (self::data()['types'] as $carveType => $entry) {
            $names = is_array($entry['pm']) ? $entry['pm'] : [$entry['pm']];
            $names = array_merge($names, $entry['accepts'] ?? []);
            if (in_array($proseMirrorName, $names, true)) {
                return $carveType;
            }
        }

        return null;
    }

    /**
     * The wire names that are NOT Carve types.
     *
     * The map has three sections a bridge has to read, not one. `types` is the
     * vocabulary; `preservationNodes` and `markCarrierNodes` name the atoms a
     * bridge writes for something the editor model has no node for at all - a
     * construct with no editable shape, and a MARK WITH NO CONTENT. Reading
     * only `types` is why `carveEmptyMark` had to be added here: a name absent
     * from every section is an error rather than a skip, so a document that was
     * only `[](https://example.com)` came back empty from one bridge and threw
     * in the other (markup-carve/carve-grammars#240).
     *
     * @return array<string, string> ProseMirror name => the section that owns it
     */
    public static function carrierNames(): array
    {
        $names = [];
        foreach (['preservationNodes', 'markCarrierNodes'] as $section) {
            /** @var array<string, mixed> $entries */
            $entries = self::data()[$section] ?? [];
            foreach ($entries as $name => $entry) {
                // `about` is the section's own prose, not a node.
                if (is_array($entry)) {
                    $names[(string)$name] = $section;
                }
            }
        }

        return $names;
    }

    public static function isCarrierName(string $proseMirrorName): bool
    {
        return array_key_exists($proseMirrorName, self::carrierNames());
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
        return array_merge(array_keys(self::data()['types']), array_keys(self::ALIASES));
    }

    /**
     * @throws \RuntimeException
     *
     * @return array{types: array<string, array{kind: string, pm: string|array<string>, accepts?: array<string>, notes?: string}>, unmapped: array<string, string>, preservationNodes?: array<string, mixed>, markCarrierNodes?: array<string, mixed>}
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

        /** @var array{types: array<string, array{kind: string, pm: string|array<string>, accepts?: array<string>, notes?: string}>, unmapped: array<string, string>, preservationNodes?: array<string, mixed>, markCarrierNodes?: array<string, mixed>} $data */
        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::$data = $data;

        return $data;
    }
}
