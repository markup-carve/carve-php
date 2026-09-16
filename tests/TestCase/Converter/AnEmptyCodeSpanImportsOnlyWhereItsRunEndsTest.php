<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An EMPTY verbatim span is a backtick run nothing closes, so it survives only
 * where the run itself ends: at the end of a block, or at the `X}` closing a
 * forced span (PART 3, UNCLOSED RUN). Anywhere else the run reads what follows
 * as its content, which PART 11 §1c makes a declared ceiling rather than a
 * spelling (markup-carve/carve-php#2044).
 */
class AnEmptyCodeSpanImportsOnlyWhereItsRunEndsTest extends TestCase
{
    protected function import(string $html): string
    {
        return (new HtmlToCarve())->convert($html);
    }

    protected function html(string $carve): string
    {
        return CarveConverter::create()->convert($carve);
    }

    protected function fmt(string $carve): string
    {
        return CarveConverter::toCarve($carve);
    }

    /**
     * The run ends here, so the span is written.
     *
     * @return array<string, array<string>>
     */
    public static function spelledProvider(): array
    {
        return [
            'a strike closes it' => ['<p><s><code></code></s></p>', "{~``~}\n"],
            'an emphasis closes it' => ['<p><em><code></code></em></p>', "{/``/}\n"],
            'a strong span closes it' => ['<p><strong><code></code></strong></p>', "{*``*}\n"],
            'a highlight closes it' => ['<p><mark><code></code></mark></p>', "{=``=}\n"],
            'an underline closes it' => ['<p><u><code></code></u></p>', "{_``_}\n"],
            'a superscript is braced already' => ['<p><sup><code></code></sup></p>', "{^``^}\n"],
            'an insertion is braced already' => ['<p><ins><code></code></ins></p>', "{+``+}\n"],
            'the inner emphasis takes the braces' => ['<p><s><em><code></code></em></s></p>', "~{/``/}~\n"],
            'text before it does not follow it' => ['<p><s>x<code></code></s></p>', "{~x``~}\n"],
            'a paragraph ends it' => ['<p>x<code></code></p>', "x``\n"],
            'the whitespace behind it is trimmed away' => [
                "<p><s>x<code></code>\n  </s></p>",
                "{~x``~}\n",
            ],
            'a heading ends it' => ['<h1><code></code></h1>', "# ``\n"],
            'a list item ends it' => ['<ul><li><code></code></li></ul>', "- ``\n"],
            'an emphasis that is not last still closes it' => [
                '<p><s><code></code></s>y</p>',
                "{~``~}y\n",
            ],
            'a forced span that is not last still closes it' => [
                '<p><sup><code></code></sup>y</p>',
                "{^``^}y\n",
            ],
        ];
    }

    #[DataProvider('spelledProvider')]
    public function testTheSpanIsWritten(string $html, string $expected): void
    {
        $this->assertSame($expected, $this->import($html));
    }

    #[DataProvider('spelledProvider')]
    public function testTheImportedSourceIsAFixedPointOfFmt(string $html, string $expected): void
    {
        $imported = $this->import($html);

        $this->assertSame($imported, $this->fmt($imported));
    }

    #[DataProvider('spelledProvider')]
    public function testFormattingTheImportDoesNotChangeWhatItSays(string $html, string $expected): void
    {
        $imported = $this->import($html);

        $this->assertSame($this->html($imported), $this->html($this->fmt($imported)));
    }

    #[DataProvider('spelledProvider')]
    public function testTheSpanSurvivesTheReadBack(string $html, string $expected): void
    {
        $this->assertStringContainsString('<code></code>', $this->html($this->import($html)));
    }

    /**
     * Nothing ends the run here, so the span has no spelling and leaves.
     *
     * @return array<string, array<string>>
     */
    public static function droppedProvider(): array
    {
        return [
            'text follows it inside a strike' => ['<p><s><code></code>y</s></p>', "~y~\n"],
            'text follows it in the paragraph' => ['<p>x<code></code>y</p>', "xy\n"],
            'an element follows it' => ['<p><s><code></code><b>y</b></s></p>', "~*y*~\n"],
            'a link closes with its own tail' => ['<p><a href="u">z<code></code></a></p>', "[z](u)\n"],
            'a quote closes with its own mark' => ['<p><q>z<code></code></q></p>', "\"z\"\n"],
            'a semantic span closes with its own tail' => [
                '<p><cite>z<code></code></cite></p>',
                "[z]{cite}\n",
            ],
            'a heading ends after the text' => ['<h1>x<code></code>y</h1>', "# xy\n"],
            'an attributed span closes with its own tail' => [
                '<p><span class="k"><code></code></span></p>',
                "[]{.k}\n",
            ],
        ];
    }

    #[DataProvider('droppedProvider')]
    public function testTheSpanIsDropped(string $html, string $expected): void
    {
        $this->assertSame($expected, $this->import($html));
    }

    #[DataProvider('droppedProvider')]
    public function testTheImportedSourceIsAFixedPointOfFmtWhenTheSpanIsDropped(string $html, string $expected): void
    {
        $imported = $this->import($html);

        $this->assertSame($imported, $this->fmt($imported));
    }

    #[DataProvider('droppedProvider')]
    public function testFormattingTheImportDoesNotChangeWhatItSaysWhenTheSpanIsDropped(string $html, string $expected): void
    {
        $imported = $this->import($html);

        $this->assertSame($this->html($imported), $this->html($this->fmt($imported)));
    }

    #[DataProvider('droppedProvider')]
    public function testNoUnclosedRunReachesTheOutput(string $html, string $expected): void
    {
        $this->assertStringNotContainsString('``', $this->import($html));
    }

    #[DataProvider('droppedProvider')]
    public function testTheLossIsReported(string $html, string $expected): void
    {
        $codes = [];
        foreach ((new HtmlToCarve())->convertWithReport($html)->diagnostics as $diagnostic) {
            $codes[] = $diagnostic->code;
        }

        $this->assertContains('structure-unspellable', $codes);
    }

    #[DataProvider('spelledProvider')]
    public function testAWrittenSpanIsNotReportedAsLost(string $html, string $expected): void
    {
        $codes = [];
        foreach ((new HtmlToCarve())->convertWithReport($html)->diagnostics as $diagnostic) {
            $codes[] = $diagnostic->code;
        }

        $this->assertNotContains('structure-unspellable', $codes);
    }

    /**
     * Two of them in one emphasis is both answers at once: the first has the
     * second behind it and goes, the second is the one the `~}` closes.
     */
    public function testOnlyTheLastOfTwoEmptySpansIsWritten(): void
    {
        $html = '<p><s><code></code><code></code></s></p>';
        $codes = [];
        foreach ((new HtmlToCarve())->convertWithReport($html)->diagnostics as $diagnostic) {
            $codes[] = $diagnostic->code;
        }

        $this->assertSame("{~``~}\n", $this->import($html));
        $this->assertSame(['structure-unspellable'], $codes);
    }

    /**
     * An attribute block attaches to a CLOSING run, which an empty span has not
     * got, so the span is written bare and the attribute is the only loss.
     */
    public function testAnAttributeOnAnEmptySpanIsTheOnlyThingDropped(): void
    {
        $this->assertSame("{~``~}\n", $this->import('<p><s><code class="c"></code></s></p>'));
    }

    public function testThatDroppedAttributeIsReported(): void
    {
        $codes = [];
        foreach ((new HtmlToCarve())->convertWithReport('<p><s><code class="c"></code></s></p>')->diagnostics as $diagnostic) {
            $codes[] = $diagnostic->code;
        }

        $this->assertContains('attribute-dropped', $codes);
    }

    /**
     * A span with CONTENT closes itself, so none of this reaches it.
     */
    public function testAFilledSpanKeepsTheBareCloserAndItsAttributes(): void
    {
        $this->assertSame("~`a`~\n", $this->import('<p><s><code>a</code></s></p>'));
        $this->assertSame("~`a`y~\n", $this->import('<p><s><code>a</code>y</s></p>'));
        $this->assertSame("`a`{.c}\n", $this->import('<p><code class="c">a</code></p>'));
    }

    /**
     * `/*` opens `bold_italic`, so the writer braces this emphasis
     * (carve-php#2012) and the importer has to agree with it.
     */
    public function testAnEmphasisHoldingAnAsteriskRunIsAFixedPoint(): void
    {
        $imported = $this->import('<p><em>*</em></p>');

        $this->assertSame($imported, $this->fmt($imported));
        $this->assertSame($this->html($imported), $this->html($this->fmt($imported)));
    }

    /**
     * A fenced block is not an inline run, and its emptiness is spellable.
     */
    public function testAnEmptyPreIsUntouched(): void
    {
        $this->assertSame("```\n\n```\n", $this->import('<pre><code></code></pre>'));
    }
}
