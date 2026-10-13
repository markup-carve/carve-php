<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class LiteralTabHtmlRoundtripTest extends TestCase
{
    public function testInteriorTabsRemainTextInEveryImportMode(): void
    {
        $converter = new CarveConverter();
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $importer = new HtmlToCarve(importMode: $mode);
            foreach (["a\tb", "[a]: \t/u", "a \t b", 'a b'] as $text) {
                $html = '<p>' . $text . '</p>';
                $tree = $importer->convertToAst($html);
                self::assertSame($text, $tree['children'][0]['children'][0]['value'], $mode . ' AST');
                self::assertSame($html, trim($converter->convert($importer->convert($html))), $mode . ' source');
            }
        }
    }

    public function testATabbedReferenceDefinitionStaysLiteralAfterHtmlImport(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/spec/tests/corpus/559-a-tab-inside-a-line-is-content-in-a-label-and-after-a-definition-marker-5.crv');
        $converter = new CarveConverter(roundTripMode: true);
        $html = $converter->convert($source);
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $back = (new HtmlToCarve(importMode: $mode))->convert($html);
            self::assertStringContainsString("[a]: \t/u", $back, $mode);
            self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($back), $mode);
        }
    }

    public function testTabsAroundHtmlLineBreaksRemainLayout(): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $importer = new HtmlToCarve(importMode: $mode);
            foreach (["a\n\t\tb", "a\t \r\n \tb"] as $text) {
                $tree = $importer->convertToAst('<p>' . $text . '</p>');
                self::assertSame('a b', $tree['children'][0]['children'][0]['value'], $mode);
            }
        }
    }
}
