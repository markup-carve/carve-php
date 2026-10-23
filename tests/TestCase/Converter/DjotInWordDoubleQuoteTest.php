<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotInWordDoubleQuoteTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'plain word' => ['ab"c', '<p>ab“c</p>'],
            'slash and code' => ['a/`b`"c', '<p>a/<code>b</code>“c</p>'],
            'code without slash' => ['a`b`"c', '<p>a<code>b</code>“c</p>'],
            'link' => ['[a](url)"c', '<p><a href="url">a</a>“c</p>'],
            'link then space' => ['[a](url)" c', '<p><a href="url">a</a>“ c</p>'],
            'attribute' => ['a{#x}"c', '<p><span id="x">a</span>“c</p>'],
            'paired word' => ['a"c"', '<p>a“c”</p>'],
            'paired link' => ['[a](url)"c"', '<p><a href="url">a</a>“c”</p>'],
            'paired attribute' => ['a{#x}"c"', '<p><span id="x">a</span>“c”</p>'],
            'existing opener' => ['"a"b"', '<p>“a”b“</p>'],
        ];
    }

    #[DataProvider('cases')]
    public function testDjotQuoteDirection(string $source, string $expected): void
    {
        $carve = (new DjotToCarve())->convert($source);

        $this->assertSame($expected, trim((new CarveConverter())->convert($carve)));
    }
}
