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

    public function testTheHtmlRawCarrierStaysOnTheImporterSTrustSetting(): void
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

    /**
     * PART 10 section 13d: two contradictory carriers restore neither meaning,
     * and the pair is reported once. An invalid value is not a carrier and
     * contradicts nothing.
     */
    public function testContradictoryLinkCarriersRestoreNeitherMeaning(): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            foreach (['data-carve-', 'data-djot-'] as $prefix) {
                $importer = new HtmlToCarve(importMode: $mode);
                $pair = '<p><a href="https://e.com" ' . $prefix . 'autolink ' . $prefix . 'ref="r">https://e.com</a></p>';
                $result = $importer->convertWithReport($pair);
                $this->assertSame("[https://e.com](https://e.com)\n", $result->value, $mode);
                $this->assertCount(1, $result->diagnostics);
                $this->assertSame('attribute-dropped', $result->diagnostics[0]->code);
                $this->assertSame('warning', $result->diagnostics[0]->severity);

                $autolink = $importer->convertWithReport('<p><a href="https://e.com" ' . $prefix . 'autolink>https://e.com</a></p>');
                $this->assertSame("<https://e.com>\n", $autolink->value, $mode);
                $this->assertSame([], $autolink->diagnostics);

                $reference = $importer->convertWithReport('<p><a href="https://e.com" ' . $prefix . 'ref="r">https://e.com</a></p>');
                $this->assertSame("[https://e.com][r]\n\n[r]: https://e.com\n", $reference->value, $mode);
                $this->assertSame([], $reference->diagnostics);

                $invalid = $importer->convertWithReport('<p><a href="https://e.com" ' . $prefix . 'autolink ' . $prefix . 'ref="a]b">https://e.com</a></p>');
                $this->assertSame("<https://e.com>\n", $invalid->value, $mode);
                $this->assertCount(1, $invalid->diagnostics);
                $this->assertSame('attribute-dropped', $invalid->diagnostics[0]->code);
            }
        }
    }

    /**
     * PART 10 section 13e: a value outside its grammar is not a carrier, and
     * the drop is reported.
     *
     * @return array<string, array{string, string, string, string}>
     */
    public static function invalidValueProvider(): array
    {
        return [
            'an id slot' => [
                '<section id="mine" data-carve-explicit-id data-carve-id-slot="x"><h1 class="c">H</h1></section>',
                "{#mine .c}\n# H\n",
                '<section id="mine" data-carve-explicit-id data-carve-id-slot="1"><h1 class="c">H</h1></section>',
                "{.c #mine}\n# H\n",
            ],
            'a signed id slot' => [
                '<section id="mine" data-carve-explicit-id data-carve-id-slot="-1"><h1 class="c">H</h1></section>',
                "{#mine .c}\n# H\n",
                '<section id="mine" data-carve-explicit-id data-carve-id-slot="1"><h1 class="c">H</h1></section>',
                "{.c #mine}\n# H\n",
            ],
            'a link reference label' => [
                '<p><a href="/u" data-carve-ref="a]b">t</a></p>',
                "[t](/u)\n",
                '<p><a href="/u" data-carve-ref="r">t</a></p>',
                "[t][r]\n\n[r]: /u\n",
            ],
            'a generated element name' => [
                '<section id="mine" data-carve-explicit-id data-carve-ext="zz"><h1>H</h1></section>',
                "{#mine}\n# H\n",
                '<section id="mine" data-carve-explicit-id><h1>H</h1></section>',
                "{#mine}\n# H\n",
            ],
        ];
    }

    #[DataProvider('invalidValueProvider')]
    public function testAnInvalidCarrierValueIsDroppedWithOneDiagnostic(
        string $invalid,
        string $invalidCarve,
        string $valid,
        string $validCarve,
    ): void {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $importer = new HtmlToCarve(importMode: $mode);
            $control = $importer->convertWithReport($valid);
            $this->assertSame($validCarve, $control->value, $mode);
            $result = $importer->convertWithReport($invalid);
            $this->assertSame($invalidCarve, $result->value, $mode);
            $dropped = static fn (array $rows): int => count(array_filter($rows, static fn ($row): bool => $row->code === 'attribute-dropped'));
            $this->assertSame(0, $dropped($control->diagnostics), $mode);
            $this->assertSame(1, $dropped($result->diagnostics), $mode);
            $this->assertCount(count($control->diagnostics) + 1, $result->diagnostics);
        }
    }
}
