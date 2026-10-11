<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Two rulings of markup-carve/carve#2902 on one preservation mode: a
 * `data-carve-escaped` span holds ONE escape (section 13a), and a dropped raw
 * format is reported with the option on as it is with the option off
 * (section 13).
 */
class OneEscapePerElementAndNoSilentRawBlockTest extends TestCase
{
    private function roundTripHtml(string $carve): string
    {
        $renderer = new HtmlRenderer();
        $renderer->setRoundTripMode(true);

        return (new CarveConverter(renderer: $renderer))->convert($carve);
    }

    public function testARunOfEscapesTakesOneSpanEach(): void
    {
        $html = $this->roundTripHtml("a\\*\\_\\*b\n");
        $this->assertSame(3, substr_count($html, '<span data-carve-escaped>'));
        foreach (['*', '_'] as $character) {
            $this->assertStringContainsString('<span data-carve-escaped>' . $character . '</span>', $html);
        }
    }

    public function testASingleEscapeSpanImportsAsOneEscape(): void
    {
        $carve = (new HtmlToCarve(importMode: 'roundtrip'))
            ->convert('<p>a<span data-carve-escaped>*</span>b</p>');
        $this->assertSame("a\\*b\n", $carve);
    }

    /**
     * A multi-character span is malformed, and reassembling it wrote `\a\b\c`.
     */
    public function testAMultiCharacterEscapeSpanIsTextNotARunOfEscapes(): void
    {
        $carve = (new HtmlToCarve(importMode: 'roundtrip'))
            ->convert('<p>x<span data-carve-escaped>abc</span>y</p>');
        $this->assertStringNotContainsString('\\a\\b\\c', $carve);
        $this->assertStringContainsString('abc', $carve);
    }

    /**
     * A raw inline's span holds TEXT. Written raw, `<b>` opened a tag the span
     * never closed and swallowed the rest of the paragraph; read back by
     * re-serializing the children, `&` came home as `&amp;`.
     */
    public function testARawInlineSpanHoldsEscapedTextAndReadsBackVerbatim(): void
    {
        foreach (["a`<b>`{=html}b\n", "a`x & y`{=latex}b\n"] as $source) {
            $html = $this->roundTripHtml($source);
            $this->assertStringNotContainsString('<b>', $html, 'the payload opened a real tag');
            $this->assertSame(
                $source,
                (new HtmlToCarve(importMode: 'roundtrip'))->convert($html),
                'the raw inline did not read back verbatim',
            );
        }
    }

    public function testADroppedRawFormatIsReportedUnderRoundtripToo(): void
    {
        $source = "``` =latex\n\\emph{x}\n```\n";
        foreach ([false, true] as $roundTrip) {
            $renderer = new HtmlRenderer();
            $renderer->setRoundTripMode($roundTrip);
            $report = (new CarveConverter(renderer: $renderer))->convertWithReport($source);
            $this->assertSame(1, $report->totalLosses, 'roundtrip mode must not silence the drop');
            $this->assertSame('raw-format-dropped', $report->losses[0]['code']);
            $this->assertSame('latex', $report->losses[0]['format']);
        }
    }
}
