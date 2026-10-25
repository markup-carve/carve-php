<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

final class MarkdownHtmlAnchorInLinkLabelTest extends TestCase
{
    public function testMatchedAnchorsFlattenInsideActiveLinkLabels(): void
    {
        $importer = new MarkdownToCarve();
        $renderer = new CarveConverter();
        foreach (
            [
                '[outer <a href="/inner">inner</a> tail](/outer)',
                '[outer <a class="x" href="/inner">inner</a> tail](/outer)',
                "[outer <a href=\"/inner\">inner</a> tail][ref]\n\n[ref]: /outer",
                '[outer <a href="/inner`path">inner</a> tail](/outer)',
                '[outer <a href="/inner``path">inner</a> tail](/outer)',
            ] as $source
        ) {
            $result = $importer->convertWithFidelityReport($source);
            $html = $renderer->convert($result->value);
            self::assertStringContainsString('<a href="/outer">outer inner tail</a>', $html);
            self::assertSame(1, substr_count($html, '<a '));
            self::assertContains('structure-unspellable', array_column($result->diagnostics, 'code'));
        }
    }

    public function testAnchorOutsideLinkLabelRemains(): void
    {
        $source = '<a href="/inner">inner</a> and [outer](/outer)';
        $html = (new CarveConverter())->convert((new MarkdownToCarve())->convert($source));
        self::assertStringContainsString('<a href="/inner">inner</a>', $html);
    }
}
