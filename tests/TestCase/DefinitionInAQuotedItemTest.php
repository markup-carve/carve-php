<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Content columns are measured INSIDE a block quote.
 *
 * `> - a` puts the item's content column at 2 of the QUOTED content. Both
 * definition prepasses fed the RAW line to the column tracker, which matches no
 * marker behind a `> `, so the column stayed 0 and a definition written at it
 * was never collected - while the item consumed the line anyway. The line
 * rendered nothing AND defined nothing, the outcome carve#624 named (carve#658).
 *
 * Un-quoted, the same shapes already worked, so the block quote was the whole
 * difference.
 */
class DefinitionInAQuotedItemTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testALinkDefinitionAtTheQuotedItemColumnResolves(): void
    {
        $html = $this->converter->convert("> - a\n>   [r]: /u\n\nsee [t][r]\n");

        $this->assertStringContainsString('href="/u"', $html);
        $this->assertStringNotContainsString('[r]: /u', $html);
    }

    public function testAFootnoteDefinitionAtTheSameColumnResolvesToo(): void
    {
        // The two definition kinds must answer the same question the same way.
        $html = $this->converter->convert("> - a\n>   [^f]: x\n\nsee[^f]\n");

        $this->assertStringContainsString('doc-endnotes', $html);
        $this->assertStringNotContainsString('[^f]: x', $html);
    }

    public function testACompactNestedQuotedItemResolvesToo(): void
    {
        $html = $this->converter->convert("> - - a\n>   [r]: /u\n\nsee [t][r]\n");

        $this->assertStringContainsString('href="/u"', $html);
    }

    public function testOneColumnShortItStaysItemText(): void
    {
        $html = $this->converter->convert("> - a\n>  [r]: /u\n\nsee [t][r]\n");

        $this->assertStringContainsString('[r]: /u', $html);
        $this->assertStringContainsString('<p>see [t][r]</p>', $html);
    }

    public function testTheUnquotedShapeIsUnchanged(): void
    {
        $html = $this->converter->convert("- a\n  [r]: /u\n\nsee [t][r]\n");

        $this->assertStringContainsString('href="/u"', $html);
    }
}
