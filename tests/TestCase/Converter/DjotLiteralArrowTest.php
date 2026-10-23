<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotLiteralArrowTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function prose(): array
    {
        return [
            'method access' => ['$this->Common->flashMessage()', '<p>$this-&gt;Common-&gt;flashMessage()</p>'],
            'Djot en dash before angle' => ['a-->b', '<p>a–&gt;b</p>'],
            'Djot en dash with spaces' => ['a --> b', '<p>a –&gt; b</p>'],
            'Djot em dash before angle' => ['a--->b', '<p>a—&gt;b</p>'],
            'Djot em dash with spaces' => ['a ---> b', '<p>a —&gt; b</p>'],
            'two Djot en dashes before angle' => ['a---->b', '<p>a––&gt;b</p>'],
            'Djot en dash after angle' => ['a<--b', '<p>a&lt;–b</p>'],
            'Djot en dash between angles' => ['a<-->b', '<p>a&lt;–&gt;b</p>'],
            'left arrow stays literal' => ['a<-b', '<p>a&lt;-b</p>'],
            'both arrows stay literal' => ['a<->b', '<p>a&lt;-&gt;b</p>'],
            'left double arrow stays literal' => ['a<==b', '<p>a&lt;==b</p>'],
            'right double arrow stays literal' => ['a==>b', '<p>a==&gt;b</p>'],
            'double arrow stays literal' => ['a<=>b', '<p>a&lt;=&gt;b</p>'],
            'less or equal stays literal' => ['a<=b', '<p>a&lt;=b</p>'],
            'greater or equal stays literal' => ['a>=b', '<p>a&gt;=b</p>'],
            'not equal stays literal' => ['a!=b', '<p>a!=b</p>'],
            'plus minus stays literal' => ['a+-b', '<p>a+-b</p>'],
            'copyright stays literal' => ['a(c)b', '<p>a(c)b</p>'],
            'registered stays literal' => ['a(r)b', '<p>a(r)b</p>'],
            'trademark stays literal' => ['a(tm)b', '<p>a(tm)b</p>'],
            'escaped arrow' => ['a \\-> b', '<p>a -&gt; b</p>'],
            'escaped hyphen before arrow' => ['a\\-->b', '<p>a--&gt;b</p>'],
            'escaped hyphen before dash' => ['a\\--->b', '<p>a-–&gt;b</p>'],
            'arrow in deletion' => ['{-a->b-}', '<p><del>a-&gt;b</del></p>'],
            'code span' => ['`a -> b`', '<p><code>a -&gt; b</code></p>'],
            'link destination' => ['[x](https://example.org/a->b)', '<p><a href="https://example.org/a-%3Eb">x</a></p>'],
        ];
    }

    #[DataProvider('prose')]
    public function testLiteralArrowSurvivesImport(string $djot, string $expected): void
    {
        $carve = (new DjotToCarve())->convert($djot);
        self::assertSame($expected, trim((new CarveConverter())->convert($carve)));
    }
}
