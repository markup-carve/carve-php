<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * The spec repository's conformance pack (`resources/conformance-pack/` at the
 * spec pin): generated variants per cause family with the spec model's HTML.
 * A differing case must be declared in fixtures/conformance-pack-drift.json
 * under the ticket for its cause; a declared case that passes, or that the
 * pack no longer has, fails too. CARVE_CONFORMANCE_PACK=<dir> runs another pack;
 * a spec pin older than the pack skips the test.
 */
final class ConformancePackTest extends TestCase
{
    public function testEveryCaseRendersAsTheSpecModelOrIsDeclared(): void
    {
        $env = getenv('CARVE_CONFORMANCE_PACK');
        $dir = is_string($env) && $env !== '' ? $env : __DIR__ . '/spec/resources/conformance-pack';
        if ($dir !== $env && !is_file("$dir/index.json")) {
            self::markTestSkipped("conformance pack: none at the spec pin yet ($dir)");
        }
        $declared = [];
        foreach (self::arr(self::read(__DIR__ . '/fixtures/conformance-pack-drift.json')['entries'] ?? null) as $entry) {
            $entry = self::arr($entry);
            foreach (self::arr($entry['cases'] ?? null) as $id) {
                $id = self::str($id);
                self::assertArrayNotHasKey($id, $declared, "$id is declared twice");
                $declared[$id] = self::str($entry['ticket'] ?? null);
            }
        }

        $seen = [];
        $undeclared = [];
        $fixed = [];
        foreach (self::arr(self::read("$dir/index.json")['families'] ?? null) as $family => $spec) {
            $spec = self::arr($spec);
            $roundtrip = ($spec['mode'] ?? null) === 'roundtrip';
            foreach (self::arr($spec['shards'] ?? null) as $shard) {
                foreach (self::read("$dir/" . self::str($shard)) as $case) {
                    $case = self::arr($case);
                    $id = self::str($case['id'] ?? null);
                    $expected = self::str($case['html'] ?? null);
                    $seen[$id] = true;
                    try {
                        $source = self::str($case['source'] ?? null);
                        $html = trim((new CarveConverter())->convert($roundtrip ? CarveConverter::toCarve($source) : $source));
                    } catch (Throwable $e) {
                        $html = 'ERROR: ' . $e->getMessage();
                    }
                    $ticket = $declared[$id] ?? null;
                    if ($html !== $expected && $ticket === null) {
                        $undeclared[] = "$id ($family)";
                    }
                    if ($html === $expected && $ticket !== null) {
                        $fixed[] = "$id ($ticket)";
                    }
                }
            }
        }
        $unknown = array_values(array_diff(array_keys($declared), array_keys($seen)));

        self::assertSame(
            ['undeclared' => [], 'fixed' => [], 'unknown' => []],
            ['undeclared' => $undeclared, 'fixed' => $fixed, 'unknown' => $unknown],
            "conformance pack at $dir: fix the engine, or declare the ids under their cause's ticket in tests/fixtures/conformance-pack-drift.json",
        );
    }

    /**
     * @throws \RuntimeException
     *
     * @return array<mixed>
     */
    private static function read(string $path): array
    {
        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException("cannot read $path");
        }

        return self::arr(json_decode($text, true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * @throws \RuntimeException
     *
     * @return array<mixed>
     */
    private static function arr(mixed $value): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('conformance pack: expected a JSON array or object');
        }

        return $value;
    }

    /**
     * @throws \RuntimeException
     */
    private static function str(mixed $value): string
    {
        if (!is_string($value)) {
            throw new RuntimeException('conformance pack: expected a JSON string');
        }

        return $value;
    }
}
