<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#2361: a link's or span's edge whitespace stands outside
 * it, presentation-only MathML imports as its text where its tokens are
 * linear, and a formula beside its fallback image imports once.
 */
class AnImportMovesEdgeSpaceOutAndReadsLinearMathMlTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function edges(): array
    {
        return [
            'the label' => [
                '<p>Source: <a href="https://jma.go.jp/"> Japan Meteorological Agency </a>.</p>',
                "Source: [Japan Meteorological Agency](https://jma.go.jp/) .\n",
            ],
            'a space already outside' => ['<p>a <a href="/x"> x</a></p>', "a [x](/x)\n"],
            'words that would merge' => ['<p>x<a href="/y"> y</a>z</p>', "x [y](/y)z\n"],
            'a block edge' => ['<p><a href="/s"> start</a></p>', "[start](/s)\n"],
            'a strong at the edge' => ['<p>a <a href="/b"> <b>bold</b> </a> b</p>', "a [*bold*](/b) b\n"],
            'an image at the edge' => ['<p>b<a href="/i"> <img src="i.png" alt="i"> </a>c</p>', "b [![i](i.png)](/i) c\n"],
            'a span' => ['<p>a<span id="k"> key </span>b</p>', "a [key]{#k} b\n"],
            'a span inside a link' => ['<p>a<a href="/n"><span class="c"> n </span></a>b</p>', "a [[n]{.c}](/n) b\n"],
            'whitespace-only content stays' => ['<p>a <a href="/w"> </a> b</p>', "a [ ](/w) b\n"],
            'a no-break space stays' => ["<p>a <a href=\"/n\">\u{a0}nb\u{a0}</a> b</p>", "a [\u{a0}nb\u{a0}](/n) b\n"],
            'the inside of a strong stays' => ['<p>a <a href="/s"><b> x </b></a>b</p>', "a [{* x *}](/s)b\n"],
        ];
    }

    #[DataProvider('edges')]
    public function testEdgeWhitespaceStandsOutside(string $html, string $carve): void
    {
        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertSame($carve, $result->value);
        $this->assertSame([], $result->report()['diagnostics']);
    }

    public function testALinearTokenRunImportsAsItsText(): void
    {
        $result = (new HtmlToCarve())->convertWithReport(
            '<p>A <math><mi>a</mi><mo>+</mo><mi>a</mi><mo>=</mo><mn>2</mn><mi>a</mi></math> B</p>',
        );

        $this->assertSame("A a+a=2a B\n", $result->value);
        $this->assertSame(['element-unwrapped'], array_column($result->report()['diagnostics'], 'code'));

        $html = '<p><math><semantics><mrow><mstyle><mi mathvariant="normal">∀</mi><mi>x</mi><mo>∈</mo><mi>X</mi>'
            . '<mo>,</mo><mspace width="1em"></mspace><mi>y</mi><mtext> if  y </mtext></mstyle></mrow>'
            . '<annotation encoding="text/plain">not this</annotation></semantics></math></p>';
        $this->assertSame("∀x∈X,yif y\n", (new HtmlToCarve())->convert($html));
        $this->assertSame(
            "1 2\n",
            (new HtmlToCarve())->convert('<p><math><mn>1</mn><mspace width="1em"></mspace><mn>2</mn></math></p>'),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function layouts(): array
    {
        return [
            'a fraction' => ['<mfrac><mn>1</mn><mn>2</mn></mfrac>'],
            'a script' => ['<msup><mi>x</mi><mn>2</mn></msup>'],
            'a phantom' => ['<mphantom><mi>x</mi></mphantom>'],
            'a glyph' => ['<mi><mglyph></mglyph></mi>'],
        ];
    }

    #[DataProvider('layouts')]
    public function testLayoutKeepsTheDrop(string $inner): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<p>v <math>' . $inner . '</math></p>');

        $this->assertSame("v\n", $result->value);
        $this->assertSame(['element-dropped'], array_column($result->report()['diagnostics'], 'code'));
    }

    public function testAFormulaBesideItsFallbackImageImportsOnce(): void
    {
        $result = (new HtmlToCarve())->convertWithReport(
            '<p>I <span class="w"><span class="m"><math><semantics><mi>a</mi>'
            . '<annotation encoding="application/x-tex">a^2</annotation></semantics></math></span>'
            . '<img src="f.svg" alt="a^2"></span> x</p>',
        );
        $this->assertSame('I [[$`a^2`]{.m}]{.w} x' . "\n", $result->value);
        $this->assertSame(['element-dropped'], array_column($result->report()['diagnostics'], 'code'));

        $result = (new HtmlToCarve())->convertWithReport(
            '<p>Or <span class="m" style="display: none"><math><mi>b</mi></math></span><img src="g.svg" alt="b^2"> there.</p>',
        );
        $this->assertSame('Or [$`b^2`]{.m} there.' . "\n", $result->value);
        $this->assertSame(
            ['style-unmapped', 'encoding-assumed', 'element-dropped'],
            array_column($result->report()['diagnostics'], 'code'),
        );
    }

    public function testTheEffectiveDisplayDecides(): void
    {
        $portrait = '<img src="portrait.png" alt="Portrait">';
        $this->assertSame(
            "x![Portrait](portrait.png)\n",
            (new HtmlToCarve())->convert('<p><math style="display:none;display:block"><mi>x</mi></math>' . $portrait . '</p>'),
        );
        $this->assertSame(
            '$`Portrait`' . "\n",
            (new HtmlToCarve())->convert('<p><math style="display:none !important;display:block"><mi>x</mi></math>' . $portrait . '</p>'),
        );
    }

    public function testAnImageThatIsNotTheFallbackStays(): void
    {
        $this->assertSame(
            "x![Portrait of Ada](portrait.png)\n",
            (new HtmlToCarve())->convert('<p><math><mi>x</mi></math><img src="portrait.png" alt="Portrait of Ada"></p>'),
        );
        $this->assertSame(
            'N $`x` ![icon](i.png) one.' . "\n",
            (new HtmlToCarve())->convert('<p>N <math alttext="x"></math> <img src="i.png" alt="icon"> one.</p>'),
        );
    }
}
