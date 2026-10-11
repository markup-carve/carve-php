<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlCarrierNames;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Documents released versions exported carry `data-djot-` names, so the
 * importer reads that spelling as a deprecated alias of the `data-carve-` one
 * (PART 10 section 13c). What the alias must buy is the SAME import, not merely
 * an import that does not crash, so every case here is a differential against
 * the canonical spelling rather than an assertion on a literal.
 */
class ADeprecatedCarrierSpellingStillImportsTest extends TestCase
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
    public function testTheDeprecatedSpellingImportsAsTheCanonicalOne(string $template): void
    {
        $legacy = str_replace('data-PREFIX-', 'data-djot-', $template);
        $canonical = str_replace('data-PREFIX-', 'data-carve-', $template);
        $this->assertNotSame($legacy, $canonical);

        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $converter = new HtmlToCarve(importMode: $mode);
            $this->assertSame(
                $converter->convert($canonical),
                (new HtmlToCarve(importMode: $mode))->convert($legacy),
                "the deprecated spelling diverged in {$mode} mode",
            );
        }
    }

    #[DataProvider('carrierProvider')]
    public function testTheDeprecatedSpellingNeverReachesTheOutput(string $template): void
    {
        $legacy = str_replace('data-PREFIX-', 'data-djot-', $template);
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $carve = (new HtmlToCarve(importMode: $mode))->convert($legacy);
            $this->assertStringNotContainsString('data-djot-', $carve);
        }
    }

    public function testTheCanonicalSpellingWinsWhereBothArePresent(): void
    {
        $both = '<p><a href="/u" data-carve-ref="canonical" data-djot-ref="legacy">text</a></p>';
        $canonicalOnly = '<p><a href="/u" data-carve-ref="canonical">text</a></p>';
        $this->assertSame(
            (new HtmlToCarve(importMode: 'roundtrip'))->convert($canonicalOnly),
            (new HtmlToCarve(importMode: 'roundtrip'))->convert($both),
        );
    }

    public function testAnUnlistedLegacyNameStaysAnAuthoredAttribute(): void
    {
        $this->assertFalse(HtmlCarrierNames::isReserved('data-carve-stored-source'));
        $carve = (new HtmlToCarve(importMode: 'roundtrip'))->convert('<p data-djot-stored-source="x">t</p>');
        $this->assertStringContainsString('data-djot-stored-source=x', $carve);
    }

    public function testStoredSourceIsTrustedUnderEitherSpelling(): void
    {
        $converter = new HtmlToCarve(trustedRoundTrip: true, importMode: 'roundtrip');
        $canonical = $converter->convert('<p data-carve-src="*stored*">rendered</p>');
        $legacy = (new HtmlToCarve(trustedRoundTrip: true, importMode: 'roundtrip'))
            ->convert('<p data-djot-src="*stored*">rendered</p>');
        $this->assertSame($canonical, $legacy);
        $this->assertStringContainsString('*stored*', $legacy);
    }
}
