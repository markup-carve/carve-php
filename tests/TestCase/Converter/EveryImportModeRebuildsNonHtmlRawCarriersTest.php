<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EveryImportModeRebuildsNonHtmlRawCarriersTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function modesAndFormats(): array
    {
        $cases = [];
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            foreach (['tex', 'html', 'HTML'] as $format) {
                $cases[$mode . ' ' . $format] = [$mode, $format];
            }
        }

        return $cases;
    }

    #[DataProvider('modesAndFormats')]
    public function testOnlyHtmlReconstructionRequiresRoundtrip(string $mode, string $format): void
    {
        $html = '<p>a <span data-carve-raw="' . $format . '">&lt;b&gt;y&lt;/b&gt;</span> b</p>';
        $reconstructs = $mode === 'roundtrip' || $format === 'tex';
        $expected = $reconstructs ? 'a `<b>y</b>`{=' . $format . "} b\n" : "a <b>y</b> b\n";
        $converter = new HtmlToCarve(importMode: $mode);

        $result = $converter->convertWithReport($html);
        $this->assertSame($expected, $result->value);
        $this->assertSame([], $result->diagnostics);
        $ast = $converter->convertToAstWithReport($html);
        $this->assertSame([], $ast->diagnostics);
        $types = array_column($ast->value['children'][0]['children'], 'type');
        $this->assertSame($reconstructs, in_array('raw_inline', $types, true));
    }

    public function testEveryModeKeepsNonHtmlBackslashesVerbatim(): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $result = (new HtmlToCarve(importMode: $mode))->convert('<p>a <span data-carve-raw="tex">\\x</span> b</p>');
            $this->assertSame('a `\\x`{=tex} b' . "\n", $result);
        }
    }

    public function testInvalidFormatsAreNotReconstructed(): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            foreach (['', 'te x', 'tex_', 'tex!', 'téx', "tex\n"] as $format) {
                $result = (new HtmlToCarve(importMode: $mode))->convertWithReport('<p>a <span data-carve-raw="' . $format . '">x</span> b</p>');
                $this->assertSame("a x b\n", $result->value, $mode . ' ' . $format);
                $this->assertSame(['attribute-dropped'], array_column($result->diagnostics, 'code'));
                $this->assertSame('Dropped invalid raw format carrier on <span>', $result->diagnostics[0]->message);
            }
        }
    }

    public function testAnElementPayloadKeepsItsMarkup(): void
    {
        $html = '<p>a <span data-carve-raw="html"><b>y</b></span></p>';

        $this->assertSame("a `<b>y</b>`{=html}\n", (new HtmlToCarve(importMode: 'roundtrip'))->convert($html));
    }

    public function testATextPayloadIsDecoded(): void
    {
        $html = '<p>a <span data-carve-raw="html">&lt;b&gt;y&lt;/b&gt;</span></p>';

        $this->assertSame("a `<b>y</b>`{=html}\n", (new HtmlToCarve(importMode: 'roundtrip'))->convert($html));
    }
}
