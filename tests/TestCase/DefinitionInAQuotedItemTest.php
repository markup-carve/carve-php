<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
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

    /**
     * Every case above writes the definition on the line under the item. With a
     * quote-marked blank between them the column tracker measured emptiness on
     * the RAW line, where `>` is not blank at all: it read as a block opener at
     * column 0, popped the item column its own quote still held open, and the
     * definition below registered nowhere while the item consumed it anyway -
     * the same "rendered nothing AND defined nothing" outcome, one blank line
     * further down (carve-php#1840).
     */
    public function testADefinitionBelowAQuoteMarkedBlankStillResolves(): void
    {
        $html = $this->converter->convert("> - a\n>\n>   [r]: /u\n>\n> see [t][r]\n");

        $this->assertStringContainsString('href="/u"', $html);
        $this->assertStringNotContainsString('[r]: /u', $html);
    }

    public function testItResolvesUnderAnOrderedItemToo(): void
    {
        $html = $this->converter->convert("> 1. a\n>\n>    [r]: /u\n>\n> see [t][r]\n");

        $this->assertStringContainsString('href="/u"', $html);
    }

    public function testItResolvesAtADeeperQuote(): void
    {
        $html = $this->converter->convert("> > - a\n> >\n> >   [r]: /u\n> >\n> > see [t][r]\n");

        $this->assertStringContainsString('href="/u"', $html);
    }

    /**
     * The column the blank now preserves has to be one the parser really opens.
     * A definition list starts ONLY on a `::` term, so an ungated single-colon
     * line handed the prepass a content column against which a visibly literal
     * definition then registered. A second bug hid this one: before the blank
     * was fixed, the quote-marked blank popped the phantom column again.
     */
    public function testASingleColonLineIsProseAndOpensNoColumn(): void
    {
        $html = $this->converter->convert("> : term\n>   def\n>\n>   [r]: /u\n>\n> see [t][r]\n");

        $this->assertStringContainsString('[r]: /u', $html);
        $this->assertStringNotContainsString('href="/u"', $html);
    }

    public function testAQuoteMarkedBlankStillEndsTheQuoteForAnUnmarkedLine(): void
    {
        $html = $this->converter->convert("> - a\n>\n[r]: /u\n\nsee [t][r]\n");

        $this->assertStringContainsString('href="/u"', $html);
    }
}
