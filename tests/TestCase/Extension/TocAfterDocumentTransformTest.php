<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CitationsExtension;
use MarkupCarve\Carve\Extension\IndexExtension;
use MarkupCarve\Carve\Extension\TocPlacementExtension;
use PHPUnit\Framework\TestCase;

class TocAfterDocumentTransformTest extends TestCase
{
    public function testTocLinksToTheFinalHeadingAfterCitations(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new TocPlacementExtension());
        $converter->addExtension(new CitationsExtension());

        $html = $converter->convert("# Main\n\n[@ref]\n\n[@ref]: Reference.\n\n::: toc\n:::\n");

        $this->assertStringContainsString('<section id="Main">', $html);
        $this->assertStringContainsString('href="#Main"', $html);
        $this->assertStringNotContainsString('href="#Main-2"', $html);
    }

    public function testTocLinksToTheFinalHeadingAfterIndex(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new TocPlacementExtension());
        $converter->addExtension(new IndexExtension());

        $html = $converter->convert("# Main\n\n:index[Zoo]\n\n::: index\n:::\n\n::: toc\n:::\n");

        $this->assertStringContainsString('<section id="Main">', $html);
        $this->assertStringContainsString('href="#Main"', $html);
        $this->assertStringNotContainsString('href="#Main-2"', $html);
    }
}
