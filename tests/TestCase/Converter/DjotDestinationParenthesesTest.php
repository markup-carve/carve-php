<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotDestinationParenthesesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function destinations(): array
    {
        return [
            'balanced link destination' => ['[x](a(b))', 'href="a(b)"'],
            'nested link destination' => ['[x](a(b(c)d))', 'href="a(b(c)d)"'],
            'escaped link destination' => ['[x](a\\(b\\))', 'href="a(b)"'],
            'balanced image destination' => ['![x](a(b))', 'src="a(b)"'],
            'nested image destination' => ['![x](a(b(c)d))', 'src="a(b(c)d)"'],
        ];
    }

    #[DataProvider('destinations')]
    public function testDestinationKeepsLiteralParentheses(string $djot, string $expected): void
    {
        $carve = (new DjotToCarve())->convert($djot);
        $html = (new CarveConverter())->convert($carve);

        self::assertStringContainsString($expected, $html);
        self::assertStringNotContainsString('%28', $html);
        self::assertStringNotContainsString('%29', $html);
    }
}
