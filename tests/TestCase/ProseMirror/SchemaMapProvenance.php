<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

/**
 * The vendored ProseMirror map measured against the carve-grammars file it
 * claims to be a copy of.
 *
 * `resources/prosemirror-schema-map.json` is a copy of carve-grammars
 * `tiptap/schema-map.json` and records the commit it came from. Nothing measured
 * that commit, so six decisions were written into the COPY and the pin stayed
 * pointing at a file that has none of them (carve-php#2326). The
 * has-a-decision test cannot see it: a locally added entry satisfies that
 * assertion by construction, which is what adding it was for.
 *
 * THE SUBJECT IS THE SET OF DECISIONS, not the commit distance. carve-grammars
 * merges continuously, so a gate on distance would be red from any open pull
 * request over there and clearable only by luck. The distance is reported as a
 * number instead, and every difference from upstream has to be NAMED in
 * `_provenance.divergences`, which is how a deliberate one is told apart from
 * drift.
 *
 * Same shape as carve-rs `tools/check-schema-map.py`, including
 * `divergences_are_live`: a declaration that no longer differs from upstream is
 * refused, so the list cannot only grow.
 *
 * Only the pure comparison lives here. `scripts/check-schema-map.php` adds the
 * git questions a carve-grammars checkout answers.
 */
final class SchemaMapProvenance
{
    /**
     * The entry keys that make a decision.
     *
     * What SchemaMap READS: `kind` selects mark or node, `pm` is what this
     * engine may emit, `accepts` widens the inbound side only. `notes` is prose
     * and `attrs`/`aliasOf` are upstream keys the loader never opens, so a
     * difference in one of them is not a decision - gating on it would put this
     * repository red for an upstream sentence nobody here has to act on.
     *
     * @var array<string>
     */
    public const DECISION_KEYS = ['kind', 'pm', 'accepts'];

    /**
     * The sections naming wire names that are not Carve types.
     *
     * SchemaMap::carrierNames() reads both, and a name absent from every
     * section is an error rather than a skip, so upstream renaming one is a
     * decision change even though no Carve type moved.
     *
     * @var array<string>
     */
    public const CARRIER_SECTIONS = ['preservationNodes', 'markCarrierNodes'];

    /**
     * What a divergence may say about upstream, and which of those a checker can
     * test.
     *
     * `prose` is the escape hatch: it carries no claim about upstream, so nothing
     * past the difference check reaches it. An unconstrained prose field cannot
     * be gated at all, which is how a reason went false in carve-rs and stayed
     * green through the decision that falsified it (markup-carve/carve#2270).
     *
     * @var array<string>
     */
    public const REASON_KINDS = ['upstream-has-no-node', 'upstream-names-a-node', 'prose'];

    /**
     * The kinds whose claim names a ProseMirror node, so the claim is testable.
     *
     * @var array<string>
     */
    public const NODE_KINDS = ['upstream-has-no-node', 'upstream-names-a-node'];

    /**
     * @var string
     */
    private const NODE_NAME = '/^carve[A-Z][A-Za-z0-9]*$/';

    /**
     * @var string
     */
    private const FULL_REV = '/^[0-9a-f]{40}$/';

    /**
     * Every decision the map states, keyed by the name that owns it.
     *
     * @param array<string, mixed> $map
     *
     * @return array<string, string> name => the decision, as one comparable string
     */
    public static function decisions(array $map): array
    {
        $out = [];

        /** @var array<string, mixed> $types */
        $types = is_array($map['types'] ?? null) ? $map['types'] : [];
        foreach ($types as $type => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $parts = ['type'];
            foreach (self::DECISION_KEYS as $key) {
                $parts[] = json_encode($entry[$key] ?? null);
            }
            $out[(string)$type] = implode('|', $parts);
        }

        /** @var array<string, mixed> $unmapped */
        $unmapped = is_array($map['unmapped'] ?? null) ? $map['unmapped'] : [];
        foreach (array_keys($unmapped) as $type) {
            $out[(string)$type] = 'unmapped';
        }

        foreach (self::CARRIER_SECTIONS as $section) {
            /** @var array<string, mixed> $entries */
            $entries = is_array($map[$section] ?? null) ? $map[$section] : [];
            foreach ($entries as $name => $entry) {
                // `about` is the section's own prose, not a node.
                if (is_array($entry)) {
                    $out['carrier:' . $name] = 'carrier|' . $section;
                }
            }
        }

        return $out;
    }

    /**
     * Every difference that has to be declared, and the declarations it used.
     *
     * @param array<string, string> $ours
     * @param array<string, string> $theirs
     * @param array<string, mixed> $divergences
     * @param string $what
     *
     * @return array{undeclared: array<string>, used: array<string>}
     */
    public static function compare(array $ours, array $theirs, array $divergences, string $what): array
    {
        $undeclared = [];
        $used = [];

        $names = array_unique(array_merge(array_keys($ours), array_keys($theirs)));
        sort($names);
        foreach ($names as $name) {
            if (($ours[$name] ?? null) === ($theirs[$name] ?? null)) {
                continue;
            }
            if (array_key_exists($name, $divergences)) {
                $used[] = $name;

                continue;
            }
            $undeclared[] = sprintf(
                '%s (here: %s, %s: %s)',
                $name,
                $ours[$name] ?? 'no decision',
                $what,
                $theirs[$name] ?? 'no decision',
            );
        }

        return ['undeclared' => $undeclared, 'used' => $used];
    }

    /**
     * Declarations that name something no longer differing from upstream.
     *
     * @param array<string, mixed> $divergences
     * @param array<string> $used
     *
     * @return array<string>
     */
    public static function staleDivergences(array $divergences, array $used): array
    {
        $stale = array_values(array_diff(array_keys($divergences), $used));
        sort($stale);

        return $stale;
    }

    /**
     * Names whose decision matches upstream but whose entry does not.
     *
     * Reported, never gated: this is the prose and `attrs` drift the decision
     * fingerprint deliberately ignores. Counting it is what keeps it from being
     * invisible, since a copy that differs only in prose is still not a copy.
     *
     * @param array<string, mixed> $ours
     * @param array<string, mixed> $theirs
     *
     * @return array<string>
     */
    public static function prose(array $ours, array $theirs): array
    {
        $differing = [];
        foreach (['types', 'unmapped', ...self::CARRIER_SECTIONS] as $section) {
            /** @var array<string, mixed> $mine */
            $mine = is_array($ours[$section] ?? null) ? $ours[$section] : [];
            /** @var array<string, mixed> $yours */
            $yours = is_array($theirs[$section] ?? null) ? $theirs[$section] : [];
            foreach ($mine as $name => $entry) {
                if (array_key_exists($name, $yours) && $yours[$name] !== $entry) {
                    $differing[] = $section . '.' . $name;
                }
            }
        }
        sort($differing);

        return $differing;
    }

    /**
     * The `_provenance` block, with whatever is wrong with it.
     *
     * @param array<string, mixed> $map
     *
     * @return array{commit: string|null, source: string|null, path: string|null, divergences: array<string, mixed>, failures: array<array{check: string, message: string}>}
     */
    public static function provenance(array $map): array
    {
        $block = $map['_provenance'] ?? null;
        if (!is_array($block)) {
            return self::noProvenance('the map carries no `_provenance` block');
        }

        $commit = is_string($block['commit'] ?? null) && $block['commit'] !== '' ? $block['commit'] : null;
        $source = is_string($block['source'] ?? null) && $block['source'] !== '' ? $block['source'] : null;
        if ($commit === null) {
            return self::noProvenance('`_provenance` names no `commit`');
        }
        if ($source === null) {
            return self::noProvenance('`_provenance` names no `source`');
        }

        $raw = $block['divergences'] ?? [];
        if (!is_array($raw)) {
            return self::noProvenance('`_provenance.divergences` is not an object');
        }
        $divergences = [];
        foreach ($raw as $name => $entry) {
            // The ENTRY, not a prose string: a bare string is what the reason
            // shape check has to be able to see and refuse.
            $divergences[(string)$name] = $entry;
        }

        $failures = [];
        if (preg_match(self::FULL_REV, $commit) !== 1) {
            $failures[] = [
                'check' => 'commit_well_formed',
                'message' => sprintf(
                    '`%s` is not a 40-character lowercase hex revision; an abbreviation '
                        . 'resolves in one checkout and not in the next',
                    $commit,
                ),
            ];
        }

        $path = self::sourcePath($source);
        if ($path === null) {
            $failures[] = [
                'check' => 'source_readable',
                'message' => sprintf(
                    '`_provenance.source` should name exactly one .json path; it reads "%s"',
                    $source,
                ),
            ];
        }

        return [
            'commit' => $commit,
            'source' => $source,
            'path' => $path,
            'divergences' => $divergences,
            'failures' => $failures,
        ];
    }

    /**
     * Whatever is wrong with the SHAPE of each declaration's reason.
     *
     * @param array<string, mixed> $divergences
     *
     * @return array<string>
     */
    public static function reasonShapes(array $divergences): array
    {
        $bad = [];
        $names = array_keys($divergences);
        sort($names);
        foreach ($names as $name) {
            $entry = $divergences[$name];
            if (!is_array($entry)) {
                $bad[] = sprintf('%s: is a %s, not an object with a `kind`', $name, get_debug_type($entry));

                continue;
            }
            $kind = $entry['kind'] ?? null;
            if (!is_string($kind) || !in_array($kind, self::REASON_KINDS, true)) {
                $bad[] = sprintf(
                    '%s: `kind` is %s, not one of %s',
                    $name,
                    json_encode($kind),
                    implode(', ', self::REASON_KINDS),
                );

                continue;
            }
            if (!is_string($entry['why'] ?? null) || trim((string)$entry['why']) === '') {
                $bad[] = sprintf('%s: says no `why`', $name);
            }
            if (!in_array($kind, self::NODE_KINDS, true)) {
                continue;
            }
            $node = $entry['node'] ?? null;
            if (!is_string($node) || $node === '') {
                $bad[] = sprintf(
                    '%s: `%s` names no `node`, so its claim about upstream cannot be tested',
                    $name,
                    $kind,
                );
            } elseif (preg_match(self::NODE_NAME, $node) !== 1) {
                $bad[] = sprintf('%s: `%s` is not a ProseMirror name upstream could publish', $name, $node);
            }
        }

        return $bad;
    }

    /**
     * Every ProseMirror name a map publishes, mapped type or not.
     *
     * The carrier sections hold named nodes belonging to no Carve type, so a
     * declaration claiming one is absent has to see them too.
     *
     * @param array<string, mixed> $map
     *
     * @return array<string>
     */
    public static function publishedNodeNames(array $map): array
    {
        $names = [];

        /** @var array<string, mixed> $types */
        $types = is_array($map['types'] ?? null) ? $map['types'] : [];
        foreach ($types as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $pm = $entry['pm'] ?? null;
            foreach (is_array($pm) ? $pm : [$pm] as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    $names[] = $candidate;
                }
            }
        }
        foreach (self::CARRIER_SECTIONS as $section) {
            /** @var array<string, mixed> $entries */
            $entries = is_array($map[$section] ?? null) ? $map[$section] : [];
            foreach ($entries as $name => $entry) {
                if (is_array($entry)) {
                    $names[] = (string)$name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Declarations whose claim about upstream is no longer true.
     *
     * Two halves per kind, and one of them is typo-proof. The NAME the entry
     * gives is resolved against the published names, which catches a rename that
     * left the type key alone; upstream's decision about the TYPE is read from
     * the key the entry is filed under, which catches a misspelled name that
     * would otherwise never resolve and let the claim stand forever.
     *
     * @param array<string, mixed> $divergences
     * @param array<string, mixed> $head
     * @param string $branch
     *
     * @return array<string>
     */
    public static function falseReasons(array $divergences, array $head, string $branch): array
    {
        $published = self::publishedNodeNames($head);
        /** @var array<string, mixed> $decided */
        $decided = is_array($head['types'] ?? null) ? $head['types'] : [];
        $false = [];
        $names = array_keys($divergences);
        sort($names);
        foreach ($names as $name) {
            $entry = $divergences[$name];
            if (!is_array($entry) || !in_array($entry['kind'] ?? null, self::NODE_KINDS, true)) {
                continue;
            }
            $node = is_string($entry['node'] ?? null) ? (string)$entry['node'] : '';
            $resolves = in_array($node, $published, true);
            if ($entry['kind'] === 'upstream-has-no-node') {
                if ($resolves) {
                    $false[] = sprintf('%s: %s publishes `%s`', $name, $branch, $node);
                } elseif (array_key_exists($name, $decided)) {
                    $false[] = sprintf('%s: %s names a node for it', $name, $branch);
                }

                continue;
            }
            if (!$resolves) {
                $false[] = sprintf('%s: %s publishes no `%s`', $name, $branch, $node);
            } elseif (!array_key_exists($name, $decided)) {
                $false[] = sprintf('%s: %s names no node for it at all', $name, $branch);
            }
        }

        return $false;
    }

    /**
     * The upstream path `_provenance.source` names, so a rename surfaces here.
     */
    public static function sourcePath(string $source): ?string
    {
        $paths = array_values(array_filter(
            preg_split('/\s+/', trim($source)) ?: [],
            static fn (string $token): bool => str_ends_with($token, '.json'),
        ));

        return count($paths) === 1 ? $paths[0] : null;
    }

    /**
     * @return array{commit: null, source: null, path: null, divergences: array<string, mixed>, failures: array<array{check: string, message: string}>}
     */
    private static function noProvenance(string $message): array
    {
        return [
            'commit' => null,
            'source' => null,
            'path' => null,
            'divergences' => [],
            'failures' => [['check' => 'provenance_present', 'message' => $message]],
        ];
    }
}
