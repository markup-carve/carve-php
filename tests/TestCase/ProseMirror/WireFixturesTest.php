<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CitationsExtension;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The ProseMirror WIRE shape, against the fixtures carve-grammars publishes.
 *
 * The schema map names the node a Carve type becomes; it did not name the
 * ATTRIBUTES, and that is where the two bridges to this same editor model
 * actually drifted - this one wrote `carveRef` and `tight` where carve-grammars
 * wrote `ref` and nothing, and each round-tripped perfectly on its own, so
 * neither test suite could see it. A document stored by one and read by the
 * other lost its reference spelling, its list tightness and its cell spans.
 *
 * These fixtures are the comparison: one Carve source per construct and the
 * exact ProseMirror document a bridge must produce for it. Refreshing them is a
 * copy from carve-grammars, like the map beside them.
 */
class WireFixturesTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function fixtureProvider(): array
    {
        $path = dirname(__DIR__, 3) . '/resources/prosemirror-wire-fixtures.json';
        /** @var array{cases: array<int, array{name: string, carve: string, pm: array<string, mixed>, extensions?: array<int, string>}>} $data */
        $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $cases = [];
        foreach ($data['cases'] as $case) {
            $cases[$case['name']] = [$case];
        }

        return $cases;
    }

    /**
     * Key ORDER is not part of a JSON object, so the comparison sorts before it
     * compares - a bridge that emits `format` before `content` produces the same
     * document, and a test that fails on it reports noise instead of drift.
     *
     * @param list<mixed>|array<string, mixed> $value
     *
     * @return list<mixed>|array<string, mixed>
     */
    private static function canonical(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = is_array($item) ? self::canonical($item) : $item;
        }
        if (!array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }

    /**
     * @param array{name: string, carve: string, pm: array<string, mixed>, extensions?: array<int, string>} $case
     */
    #[DataProvider('fixtureProvider')]
    public function testTheBridgeProducesTheWireShape(array $case): void
    {
        $converter = new CarveConverter();
        foreach ($case['extensions'] ?? [] as $extension) {
            if ($extension === 'citations') {
                $converter->addExtension(new CitationsExtension());
            }
        }

        $renderer = new ProseMirrorRenderer();
        $actual = $renderer->render($converter->parse($case['carve']));

        $this->assertSame(
            self::canonical($case['pm']),
            self::canonical($actual),
            $case['name'] . ' does not match the published wire shape',
        );
    }

    /**
     * @param array{name: string, carve: string, pm: array<string, mixed>, extensions?: array<int, string>} $case
     */
    #[DataProvider('fixtureProvider')]
    public function testTheBridgeReadsTheWireShapeBack(array $case): void
    {
        $converter = new CarveConverter();
        foreach ($case['extensions'] ?? [] as $extension) {
            if ($extension === 'citations') {
                $converter->addExtension(new CitationsExtension());
            }
        }

        // A fixture nothing can read back would pin a shape that loses content,
        // so both directions are checked against the same document.
        $back = (new ProseMirrorToCarve())->convert($case['pm']);
        $written = CarveConverter::carve()->render($back);

        $this->assertSame(
            trim(CarveConverter::carve()->render($converter->parse($case['carve']))),
            trim($written),
            $case['name'] . ' does not come back as the Carve it was written from',
        );
    }
}
