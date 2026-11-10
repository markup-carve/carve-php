<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\Html\CarrierNames;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5735 retired the `data-djot-` carrier alias: a former carrier name is an
 * ordinary unreserved `data-*` attribute (CARVE-P10-016), so each case is a
 * differential against an unrelated `data-` name.
 */
class AFormerCarrierSpellingIsAnOrdinaryAttributeTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function carrierProvider(): array
    {
        return [
            'an explicit heading id' => ['<h2 id="h" data-PREFIX-explicit-id="1">T</h2>'],
            'a heading source level' => ['<h2 id="h" data-PREFIX-source-level="1">T</h2>'],
            'a reference link' => ['<p><a href="/u" data-PREFIX-ref="lbl">text</a></p>'],
            'a reference image' => ['<p><img src="/i.png" alt="a" data-PREFIX-ref="lbl"></p>'],
            'an autolink' => ['<p><a href="https://e.test" data-PREFIX-autolink="1">https://e.test</a></p>'],
            'a footnote reference' => ['<p><a href="#fn1" data-PREFIX-footnote-label="note">1</a></p>'],
            'an escaped character' => ['<p>a<span data-PREFIX-escaped="1">*</span>b</p>'],
            'a raw inline' => ['<p>a<span data-PREFIX-raw="html">&lt;b&gt;</span>b</p>'],
            'separator widths' => [
                '<table data-PREFIX-col-widths="3,5"><thead><tr><th>a</th><th>b</th></tr></thead>'
                . '<tbody><tr><td>c</td><td>d</td></tr></tbody></table>',
            ],
            'abbreviation definitions' => [
                '<template data-PREFIX-abbreviations>*[HTML]: HyperText</template><p>HTML</p>',
            ],
            'an admonition' => [
                '<div class="admonition note" data-PREFIX-admonition-type="note" '
                . 'data-PREFIX-admonition-title="Heads up"><p>b</p></div>',
            ],
            'a heading reference' => ['<p><a href="#t" data-PREFIX-heading-ref="Target">Target</a></p>'],
            'an inline footnote' => [
                '<ol><li id="fn1" data-PREFIX-inline-footnote="1" data-PREFIX-footnote-label="1"><p>n</p></li></ol>',
            ],
        ];
    }

    #[DataProvider('carrierProvider')]
    public function testTheFormerSpellingImportsAsAnOrdinaryAttribute(string $template): void
    {
        $former = str_replace('data-PREFIX-', 'data-djot-', $template);
        $ordinary = str_replace('data-PREFIX-', 'data-xq-', $template);
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $this->assertSame(
                str_replace('data-xq-', 'data-djot-', (new HtmlToCarve(importMode: $mode))->convert($ordinary)),
                (new HtmlToCarve(importMode: $mode))->convert($former),
                "the former spelling diverged from an ordinary attribute in {$mode} mode",
            );
        }
    }

    public function testTheFormerSpellingRecoversNoReference(): void
    {
        $html = '<p><a href="/u" data-djot-ref="r">t</a></p>';
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $ast = (new HtmlToCarve(importMode: $mode))->convertToAst($html);
            $link = $ast['children'][0]['children'][0];
            $this->assertSame('link', $link['type']);
            $this->assertArrayNotHasKey('ref', $link);
            $this->assertCount(1, $ast['children']);
        }
        $this->assertSame("[t](/u){data-djot-ref=r}\n", (new HtmlToCarve(importMode: 'roundtrip'))->convert($html));
        $this->assertSame("[t][r]\n\n[r]: /u\n", (new HtmlToCarve(importMode: 'roundtrip'))->convert('<p><a href="/u" data-carve-ref="r">t</a></p>'));
    }

    public function testTheCanonicalCarrierKeepsTheFormerNameAsAnAttribute(): void
    {
        $both = '<p><a href="/u" data-carve-ref="r" data-djot-ref="z">t</a></p>';
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $link = (new HtmlToCarve(importMode: $mode))->convertToAst($both)['children'][0]['children'][0];
            $this->assertSame('r', $link['ref']);
            $this->assertSame('z', $link['attrs']['keyValues']['data-djot-ref']);
        }
    }

    public function testTheFormerNamesAreNotReserved(): void
    {
        $this->assertFalse(CarrierNames::isReserved('data-djot-ref'));
        $this->assertFalse(CarrierNames::isStoredSourceMarker('data-djot-src'));
        $this->assertTrue(CarrierNames::isStoredSourceMarker('data-carve-src'));
    }

    public function testAnUnlistedLegacyNameStaysAnAuthoredAttribute(): void
    {
        $this->assertFalse(CarrierNames::isReserved('data-carve-stored-source'));
        $carve = (new HtmlToCarve(importMode: 'roundtrip'))->convert('<p data-djot-stored-source="x">t</p>');
        $this->assertStringContainsString('data-djot-stored-source=x', $carve);
    }
}
