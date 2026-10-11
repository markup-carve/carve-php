<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlCarrierNames;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 10 section 13d: consuming a carrier reconstructs syntax the AST can
 * already express, so it is not a trust decision and is not gated on an import
 * mode. The defect it replaces is the WRITE-BACK: `safe` used to return
 * `<hr data-char="_">` as `{data-char=_}` plus `---`, and a reference link as
 * `[text](/u){data-carve-ref=lbl}`, against HtmlToCarve's own docblock.
 */
class EveryImportModeConsumesTheCarrierInventoryTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function consumedProvider(): array
    {
        return [
            'a thematic break character' => ['<hr data-char="_">', "___\n"],
            'a list marker' => ['<ul data-marker="*"><li>a</li></ul>', "* a\n"],
            'a reference link' => ['<p><a href="/u" data-carve-ref="lbl">text</a></p>', "[text][lbl]\n\n[lbl]: /u\n"],
            'an escaped character' => ['<p>a<span data-carve-escaped>*</span>b</p>', "a\\*b\n"],
            'abbreviation definitions' => [
                '<template data-carve-abbreviations>*[HTML]: HyperText</template><p>HTML</p>',
                "*[HTML]: HyperText\n\nHTML\n",
            ],
        ];
    }

    #[DataProvider('consumedProvider')]
    public function testEveryModeReconstructsTheSameSyntax(string $html, string $carve): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $this->assertSame($carve, (new HtmlToCarve(importMode: $mode))->convert($html), "mode {$mode}");
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reservedProvider(): array
    {
        return [
            'a thematic break character' => ['<hr data-char="_">'],
            'a reference link' => ['<p><a href="/u" data-carve-ref="lbl">text</a></p>'],
            'a reference image' => ['<p><img src="/i.png" alt="a" data-carve-ref="lbl"></p>'],
            'an explicit heading id' => ['<h2 id="h" data-carve-explicit-id="1">T</h2>'],
            'a footnote label' => ['<p><a href="#fn1" data-carve-footnote-label="n">1</a></p>'],
            'stored source' => ['<p data-carve-src="*s*">t</p>'],
        ];
    }

    /**
     * `data-carve-raw` is the ONE carrier a mode still gates, because it
     * returns its payload verbatim as a raw inline, which PART 10 section 13e
     * leaves on the importer's trust setting. Consuming the others
     * reconstructs syntax; consuming this one lets foreign content back in.
     */
    public function testTheRawCarrierStaysOnTheImporterSTrustSetting(): void
    {
        $html = '<p>a<span data-carve-raw="html">&lt;b&gt;</span>b</p>';

        $this->assertSame("a`<b>`{=html}b\n", (new HtmlToCarve(importMode: 'roundtrip'))->convert($html));
        foreach (['safe', 'semantic'] as $mode) {
            $this->assertStringNotContainsString('{=html}', (new HtmlToCarve(importMode: $mode))->convert($html));
        }
    }

    /**
     * Losing the escape is not a cosmetic loss: the bare `*` reparses as
     * emphasis, so the document changes meaning.
     */
    public function testASafeImportKeepsAnEscapeItWouldOtherwiseReparse(): void
    {
        $carve = (new HtmlToCarve(importMode: 'safe'))->convert('<p>a<span data-carve-escaped>*</span>b*c</p>');

        $this->assertStringContainsString('\\*', $carve);
    }

    #[DataProvider('reservedProvider')]
    public function testNoReservedNameIsWrittenBackAsAnAuthoredAttribute(string $html): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $carve = (new HtmlToCarve(importMode: $mode))->convert($html);
            foreach (array_merge(HtmlCarrierNames::reserved(), ['data-marker', 'data-char']) as $name) {
                $this->assertStringNotContainsString($name, $carve, "{$name} was written back in {$mode} mode");
            }
        }
    }
}
