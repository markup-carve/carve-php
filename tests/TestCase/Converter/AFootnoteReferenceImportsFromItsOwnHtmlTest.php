<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The engine's own footnote reference -
 * `<a id="fnrefN" href="#fnN" role="doc-noteref"><sup>N</sup></a>` -
 * imports back as `[^N]`, so the reference and the definition the endnotes
 * section already yields stay bound.
 *
 * It used to import as a literal link carrying a superscript span
 * (`[{^1^}](#fn1){#fnref1}`), which left the imported definition unused and
 * the endnotes section gone on the next render (carve-php#1286). The label
 * is derived from the `#fnN` fragment, the same derivation the definition
 * side applies to the list item's id.
 */
class AFootnoteReferenceImportsFromItsOwnHtmlTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'one reference' => ["a[^n] b\n\n[^n]: the note body\n"],
            'a shared and a second note' => ["a[^x] b[^x] c[^y]\n\n[^x]: shared\n\n[^y]: other\n"],
            'a definition with block content' => ["ref[^b]\n\n[^b]: para one\n\n  para two\n"],
        ];
    }

    /**
     * @param string $source
     */
    #[DataProvider('sourceProvider')]
    public function testTheRenderedHtmlSurvivesTheImport(string $source): void
    {
        $converter = new CarveConverter();
        $html = $converter->convert($source);
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertSame(
            $html,
            (new CarveConverter())->convert($imported),
            "importing the rendered HTML must reproduce it; imported source was:\n" . $imported,
        );
    }

    public function testTheImportedSourceSpellsAReference(): void
    {
        $html = (new CarveConverter())->convert("a[^n] b\n\n[^n]: the note body\n");
        $imported = (new HtmlToCarve())->convert($html);

        $this->assertStringContainsString('[^1]', $imported);
        $this->assertStringNotContainsString('(#fn1)', $imported);
    }
}
