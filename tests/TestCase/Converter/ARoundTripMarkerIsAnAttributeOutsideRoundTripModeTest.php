<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ARoundTripMarkerIsAnAttributeOutsideRoundTripModeTest extends TestCase
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
        $html = "<hr data-char=\"*\">\n<p><a href=\"/x\" data-djot-ref=\"r\">a</a></p>\n";
        $importer = new HtmlToCarve(false, [], false, $mode);
        $expected = $mode === 'roundtrip'
            ? "***\n\n[a][r]\n\n[r]: /x\n"
            : "{data-char=*}\n---\n\n[a](/x){data-djot-ref=r}\n";

        self::assertSame($expected, $importer->convert($html));
        $ast = $importer->convertToAstWithReport($html)->value;
        $break = $ast['children'][0];
        $link = $ast['children'][1]['children'][0];
        if ($mode === 'roundtrip') {
            self::assertSame('*', $break['marker']);
            self::assertSame('r', $link['ref']);
            self::assertArrayNotHasKey('data-char', $break['attrs']['keyValues'] ?? []);
            self::assertArrayNotHasKey('data-djot-ref', $link['attrs']['keyValues'] ?? []);
        } else {
            self::assertSame('*', $break['attrs']['keyValues']['data-char']);
            self::assertSame('r', $link['attrs']['keyValues']['data-djot-ref']);
            self::assertArrayNotHasKey('marker', $break);
            self::assertArrayNotHasKey('ref', $link);
        }
    }

    #[DataProvider('modes')]
    public function testImageMarker(string $mode): void
    {
        $html = '<img src="/i.png" alt="a" data-djot-ref="r">';
        $importer = new HtmlToCarve(false, [], false, $mode);
        $expected = $mode === 'roundtrip'
            ? "![a][r]\n\n[r]: /i.png\n"
            : "![a](/i.png){data-djot-ref=r}\n";

        self::assertSame($expected, $importer->convert($html));
        $ast = $importer->convertToAstWithReport($html)->value;
        $image = $ast['children'][0];
        if ($mode === 'roundtrip') {
            self::assertArrayNotHasKey('data-djot-ref', $image['attrs']['keyValues'] ?? []);
        } else {
            self::assertSame('r', $image['attrs']['keyValues']['data-djot-ref']);
            self::assertArrayNotHasKey('ref', $image);
        }
    }
}
