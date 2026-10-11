<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * This case used to pin the opposite: outside roundtrip mode a marker came
 * back as an AUTHORED attribute, so `<hr data-char="*">` imported as
 * `{data-char=*}` plus `---` and a reference link as
 * `[a](/x){data-carve-ref=r}`.
 *
 * PART 10 section 13d overturned it. Consuming a carrier reconstructs syntax
 * the AST can already express, which is not a trust decision, and writing a
 * reserved name into the reconstructed document was the defect - one
 * `HtmlToCarve`'s own docblock already said did not happen.
 */
class ARoundTripMarkerIsConsumedInEveryImportModeTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function modes(): array
    {
        return [
            'safe' => ['safe'],
            'semantic' => ['semantic'],
            'roundtrip' => ['roundtrip'],
        ];
    }

    #[DataProvider('modes')]
    public function testBreakAndLinkMarkers(string $mode): void
    {
        $html = "<hr data-char=\"*\">\n<p><a href=\"/x\" data-carve-ref=\"r\">a</a></p>\n";
        $importer = new HtmlToCarve(false, [], false, $mode);

        self::assertSame("***\n\n[a][r]\n\n[r]: /x\n", $importer->convert($html));

        $ast = $importer->convertToAstWithReport($html)->value;
        $break = $ast['children'][0];
        $link = $ast['children'][1]['children'][0];
        self::assertSame('*', $break['marker']);
        self::assertSame('r', $link['ref']);
        self::assertArrayNotHasKey('data-char', $break['attrs']['keyValues'] ?? []);
        self::assertArrayNotHasKey('data-carve-ref', $link['attrs']['keyValues'] ?? []);
    }

    #[DataProvider('modes')]
    public function testImageMarker(string $mode): void
    {
        $html = '<img src="/i.png" alt="a" data-carve-ref="r">';
        $importer = new HtmlToCarve(false, [], false, $mode);

        self::assertSame("![a][r]\n\n[r]: /i.png\n", $importer->convert($html));

        $image = $importer->convertToAstWithReport($html)->value['children'][0];
        self::assertArrayNotHasKey('data-carve-ref', $image['attrs']['keyValues'] ?? []);
    }
}
