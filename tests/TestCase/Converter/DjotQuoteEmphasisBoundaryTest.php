<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotQuoteEmphasisBoundaryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'slash inside quoted emphasis' => ["_'a/'_", '<p><em>’a/’</em></p>'];
        yield 'slash before closing quote' => ["x _a/'_ y", '<p>x <em>a/’</em> y</p>'];
        yield 'crossing quote and emphasis' => ['"a _b/" c_', '<p>“a _b/” c_</p>'];
        yield 'slash within paired quotes' => ["_'a/b'_", '<p><em>’a/b’</em></p>'];
        yield 'emphasis inside quotes' => ['"a _b_ c"', '<p>“a <em>b</em> c”</p>'];
        yield 'double quote after slash' => ['_"a/"_', '<p><em>“a/”</em></p>'];
        yield 'single quote before more text' => ["_a/'b_", '<p><em>a/’b</em></p>'];
        yield 'strong crosses quote' => ['"a *b" c*', '<p>“a *b” c*</p>'];
        yield 'explicit emphasis crosses quote' => ['"a {_b" c_}', '<p>“a {_b” c_}</p>'];
        yield 'crossing emphasis with word attribute' => ['"a _b" c_{.x}', '<p>“a _b” <span class="x">c_</span></p>'];
        yield 'crossing strong with word attribute' => ['"a *b" c*{.x}', '<p>“a *b” <span class="x">c*</span></p>'];
        yield 'braced crossing with attribute' => ['"a {_b" c_}{.x}', '<p>“a {_b” <span class="x">c_}</span></p>'];
        yield 'braced strong crossing with attribute' => ['"a {*b" c*}{.x}', '<p>“a {*b” <span class="x">c*}</span></p>'];
        yield 'doubled closer with attribute' => ['"a _b" c__{.x}', '<p>“a _b” <span class="x">c__</span></p>'];
        yield 'markup before attributed closer' => ['"a _b" *c*_{.x}', '<p>“a _b” <strong>c</strong><span class="x">_</span></p>'];
    }

    #[DataProvider('cases')]
    public function testDjotQuoteAndEmphasisBoundaries(string $source, string $expected): void
    {
        $carve = (new DjotToCarve())->convert($source);

        self::assertSame($expected, trim((new CarveConverter())->convert($carve)));
    }
}
