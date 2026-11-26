<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\PatchError;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Candidate17TreeCallsTest extends TestCase
{
    public function testCanonicalNumberSpellings(): void
    {
        $document = new Node('document', []);
        $document->f['astVersion'] = '2.0';
        $document->f['props'] = ['b' => 1e-7, 'a' => 1e-6, 'c' => 1e21, 'd' => -0.0];

        self::assertStringContainsString(
            '"props":{"a":0.000001,"b":1e-7,"c":1e+21,"d":0}',
            TreeEncoder::json($document, null),
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        $path = dirname(__DIR__) . '/spec/conformance/clause-cases.json';
        $cases = json_decode((string)file_get_contents($path), true, 4096, JSON_THROW_ON_ERROR)['cases'];
        $out = [];
        foreach ($cases as $case) {
            $number = (int)substr($case['id'], 3);
            if ($number >= 1319 && $number <= 1338) {
                $out[$case['id']] = [$case];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testRuledTreeCall(array $case): void
    {
        $source = isset($case['inputBytes']) ? hex2bin(str_replace(' ', '', $case['inputBytes'])) : $case['input'];
        $tree = static fn (string $text): Node => clone Carve::parse($text);
        $expected = $case['expected'];
        if ($case['kind'] === 'fingerprint') {
            $actual = ($case['of'] ?? null) === 'tree'
                ? Carve::treeFingerprint($tree($source)) : Carve::sourceFingerprint($source);
            self::assertSame($expected['fingerprint'], $actual);

            return;
        }
        if ($case['kind'] === 'diff') {
            $patch = Carve::diffTree($tree($source), $tree($case['inputAfter']));
            if (isset($expected['patch'])) {
                self::assertSame($expected['patch'], $patch);
            }
            $after = Carve::applyPatch($tree($source), $patch);
            self::assertSame(Carve::encodeTree($tree($case['inputAfter'])), Carve::encodeTree($after));

            return;
        }
        $fingerprint = isset($case['fingerprintOf']) ? Carve::treeFingerprint($tree($case['fingerprintOf'])) : null;
        try {
            $after = Carve::applyPatch($tree($source), $case['patch'], $fingerprint === null ? [] : ['fingerprint' => $fingerprint]);
        } catch (PatchError $error) {
            self::assertSame($expected['refusal']['path'], $error->pointer);

            return;
        }
        self::assertArrayNotHasKey('refusal', $expected);
        if (isset($expected['children'])) {
            $actual = json_decode(Carve::encodeTree($after), true, 4096, JSON_THROW_ON_ERROR);
            self::assertSame(self::sorted($expected['children']), self::sorted($actual['children']));
        }
    }

    private static function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sorted(...), $value);
    }
}
