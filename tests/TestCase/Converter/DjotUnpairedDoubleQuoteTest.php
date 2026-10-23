<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotUnpairedDoubleQuoteTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function quotes(): array
    {
        return [
            'slash inside preceding word' => ['x/y" z', '<p>x/y“ z</p>'],
            'unpaired inside slash word' => ['x/y"z', '<p>x/y“z</p>'],
            'pair after slash word' => ['a/b"c"', '<p>a/b“c”</p>'],
            'adjacent pair after slash word' => ['a/b""c"', '<p>a/b““c”</p>'],
            'punctuation after slash word' => ['a/b," c', '<p>a/b,“ c</p>'],
            'paired closer after slash word' => ['"a/b"', '<p>“a/b”</p>'],
            'paired closer after spaced slash word' => ['"x a/b", y', '<p>“x a/b”, y</p>'],
        ];
    }

    #[DataProvider('quotes')]
    public function testDoubleQuoteDirectionMatchesDjot(string $source, string $expected): void
    {
        $html = trim((new CarveConverter())->convert((new DjotToCarve())->convert($source)));

        self::assertSame($expected, $html);
    }
}
