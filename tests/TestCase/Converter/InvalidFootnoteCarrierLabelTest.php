<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvalidFootnoteCarrierLabelTest extends TestCase
{
    #[DataProvider('invalidLabels')]
    public function testInvalidLabelsUseTheOrdinaryAnchorAndReportTheLoss(string $label, string $mode): void
    {
        $html = '<p><a href="#fn1" data-carve-footnote-label="' . $label . '">1</a></p>';
        $result = (new HtmlToCarve(importMode: $mode))->convertWithReport($html);

        self::assertSame("[1](#fn1)\n", $result->value);
        self::assertStringNotContainsString('data-carve-footnote-label', $result->value);
        self::assertContains('attribute-dropped', array_map(static fn ($row): string => $row->code, $result->diagnostics));
    }

    /**
     * @return iterable<array{string, string}>
     */
    public static function invalidLabels(): iterable
    {
        foreach (['safe', 'roundtrip'] as $mode) {
            foreach (['a&#10;b', 'a&#13;b', 'a]b', 'a]: body', 'a&#10;```{=html}&#10;&lt;script&gt;bad&lt;/script&gt;&#10;```', ''] as $label) {
                yield [$label, $mode];
            }
        }
    }

    #[DataProvider('modes')]
    public function testInvalidDefinitionCarrierUsesTheValidIdLabel(string $mode): void
    {
        $html = '<p><a href="#fn1" role="doc-noteref">1</a></p>'
            . '<section role="doc-endnotes"><ol><li id="fn1" data-carve-footnote-label="a&#10;b"><p>Note</p></li></ol></section>';
        $result = (new HtmlToCarve(importMode: $mode))->convertWithReport($html);

        self::assertSame("[^1]\n\n[^1]: Note\n", $result->value);
        self::assertContains('attribute-dropped', array_map(static fn ($row): string => $row->code, $result->diagnostics));
    }

    #[DataProvider('modes')]
    public function testValidLabelRetainsItsSpaces(string $mode): void
    {
        $html = '<p><a href="#fn1" data-carve-footnote-label="a b">1</a></p>';
        self::assertSame("[^a b]\n", (new HtmlToCarve(importMode: $mode))->convert($html));
    }

    /**
     * @return iterable<array{string}>
     */
    public static function modes(): iterable
    {
        yield ['safe'];
        yield ['roundtrip'];
    }
}
