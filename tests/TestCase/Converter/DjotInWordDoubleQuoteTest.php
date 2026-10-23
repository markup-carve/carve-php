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

    public function testHeadingQuoteDirectionKeepsGeneratedIds(): void
    {
        $converter = new DjotToCarve();
        $renderer = new CarveConverter();
        $cases = [
            ['# ab"c', 'id="ab&quot;c"', '<h1>ab“c</h1>'],
            ['> # x"y"', 'id="x&quot;y&quot;"', '<h1 id="x&quot;y&quot;">x“y”</h1>'],
            ["# a\nb\"c", 'id="a-b&quot;c"', '<h1>a b“c</h1>'],
        ];
        foreach ($cases as [$source, $id, $heading]) {
            $html = $renderer->convert($converter->convert($source));

            $this->assertStringContainsString($id, $html);
            $this->assertStringContainsString($heading, $html);
        }
    }
}
