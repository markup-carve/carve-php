<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 0, A NEW MARKER DOES NOT REACH A DEAD CONTAINER'S COLUMN (carve#1892).
 * A blank ends every open quote and every column opened inside one dies with
 * it, so a later line that writes the marker again opens a NEW quote and
 * inherits nothing. A definition two columns above that quote's content column
 * with no item open is paragraph text: published where it was written and
 * registering nothing.
 *
 * The quoteDepth on each column (carve-php#1431) separates the two container
 * sequences that reach the same number, but a re-marked line reaches the SAME
 * depth - so depth alone let the dead item's column survive, and the definition
 * registered document-wide while the page printed it as ordinary text.
 */
class ANewMarkerDoesNotReachADeadContainerColumnTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testTheDefinitionIsPublishedAndRegistersNothing(): void
    {
        $html = $this->converter->convert("> - x\n\n>   [r]: /url\n\nsee [t][r]\n");

        $this->assertStringContainsString('[r]: /url', $html);
        $this->assertStringNotContainsString('href="/url"', $html);
    }

    public function testItHoldsForAnOrderedItemToo(): void
    {
        $html = $this->converter->convert("> 1. x\n\n>    [r]: /url\n\nsee [t][r]\n");

        $this->assertStringNotContainsString('href="/url"', $html);
    }

    /**
     * A quote-marked blank does NOT end the quote, so the item column survives
     * it and the definition still registers (carve-php#1840).
     */
    public function testAQuoteMarkedBlankStillRegisters(): void
    {
        $html = $this->converter->convert("> - x\n>\n>   [r]: /url\n>\n> see [t][r]\n");

        $this->assertStringContainsString('href="/url"', $html);
    }

    public function testTheAdjacentShapeStillRegisters(): void
    {
        $html = $this->converter->convert("> - x\n>   [r]: /url\n>\n> see [t][r]\n");

        $this->assertStringContainsString('href="/url"', $html);
    }

    /**
     * At document level a list item IS transparent across a blank, so the
     * unquoted shape is untouched by this.
     */
    public function testAnUnquotedLooseItemStillRegisters(): void
    {
        $html = $this->converter->convert("- x\n\n  [r]: /url\n\nsee [t][r]\n");

        $this->assertStringContainsString('href="/url"', $html);
    }
}
