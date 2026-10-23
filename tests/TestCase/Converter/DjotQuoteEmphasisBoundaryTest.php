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
        yield 'isolated quote after emphasis opener' => ["_'_", '<p><em>’</em></p>'];
        yield 'double quote after closing single' => ["_'\"a\"_", '<p><em>’“a”</em></p>'];
        yield 'unmatched double after closing single' => ["_'\"_", '<p><em>’“</em></p>'];
        yield 'strong with double quote after closing single' => ["*'\"a\"*", '<p><strong>’“a”</strong></p>'];
        yield 'adjacent double quotes after closing single' => ["_'\"\"_", '<p><em>’““</em></p>'];
        yield 'spaced double quotes after closing single' => ["_'\" \"_", '<p><em>’“ “</em></p>'];
        yield 'later paired double after opening double' => ["_'\"\" a\"_", '<p><em>’““ a”</em></p>'];
        yield 'two opening doubles after a space' => ["_'\"a \"\"_", '<p><em>’“a ““</em></p>'];
        yield 'paired doubles after a space' => ["_'\"a \"b\"\"_", '<p><em>’“a “b””</em></p>'];
        yield 'unpaired double after a closed pair' => ["_'\"a\" b\"_", '<p><em>’“a” b“</em></p>'];
        yield 'escaped double before closing double' => ["_'\"a\\\"\"_", '<p><em>’“a"”</em></p>'];
        yield 'paired single after closing single' => ["_''a'_", '<p><em>’‘a’</em></p>'];
        yield 'paired single after two closing singles' => ["_'''a'_", '<p><em>’’‘a’</em></p>'];
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
        yield 'link before attributed closer' => ['"a _b" [c](u)_{.x}', '<p>“a _b” <a href="u">c</a><span class="x">_</span></p>'];
        yield 'adjacent quote before attributed word' => ['"a _b"c_{.x}', '<p>“a _b”<span class="x">c_</span></p>'];
        yield 'mixed literal markers with attribute' => ['"a *b" c_*{.x}', '<p>“a *b” <span class="x">c_*</span></p>'];
        yield 'subscript before attributed closer' => ['"a _b" ~c~_{.x}', '<p>“a _b” <sub>c</sub><span class="x">_</span></p>'];
        yield 'doubled closer opens later emphasis' => ['"a _b" c__ d_', '<p>“a _b” c<em>_ d</em></p>'];
        yield 'attributed closer opens later emphasis' => ['"a _b" c_{.x} d_', '<p>“a _b” c<em> d</em></p>'];
        yield 'later crossing closes forced emphasis' => ['"a _b" c_{.x} "d _e" f_{.y}', '<p>“a _b” c<em class="y"> “d _e” f</em></p>'];
        yield 'doubled closer drops opener attribute' => ['"a _b" c__{.x} d_', '<p>“a _b” c_<em> d</em></p>'];
        yield 'longer doubled closer drops attribute' => ['"a _b" c___{.x} d_', '<p>“a _b” c__<em> d</em></p>'];
        yield 'mixed closer drops attribute' => ['"a _b" c_*{.x} d*', '<p>“a _b” c_<strong> d</strong></p>'];
        yield 'forced closer followed by attributed opener' => ['"a _b" c_{.x} d__{.y} e_', '<p>“a _b” c<em> d</em><em> e</em></p>'];
    }

    #[DataProvider('cases')]
    public function testDjotQuoteAndEmphasisBoundaries(string $source, string $expected): void
    {
        $carve = (new DjotToCarve())->convert($source);

        self::assertSame($expected, trim((new CarveConverter())->convert($carve)));
    }
}
