<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotLiteralCloserAttributesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function literalDelimiterProvider(): iterable
    {
        $rows = json_decode(file_get_contents(__DIR__ . '/../../fixtures/djot-literal-closer-attributes.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            yield $row['name'] => [$row['source'], $row['html']];
        }
    }

    #[DataProvider('literalDelimiterProvider')]
    public function testLiteralDelimiterBoundaries(string $source, string $expected): void
    {
        $html = trim((new CarveConverter())->convert((new DjotToCarve())->convert($source)));
        $html = preg_replace('/<\/?tbody>/', '', $html);
        $html = preg_replace('/>\s+</', '><', $html);
        self::assertSame($expected, $html);
    }
}
