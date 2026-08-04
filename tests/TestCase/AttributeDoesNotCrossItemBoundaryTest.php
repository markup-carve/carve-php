<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §15: a pending attribute floats to the next VISIBLE block (A2a) and a
 * run that reaches the end with nothing to attach to is dropped (A4). A list
 * ITEM boundary is such an end.
 *
 * The pending-attribute run is parser state, so an attribute written inside
 * one item and finding no block there simply survived into the NEXT item's
 * parse and attached to its paragraph - which would make a `{...}` line's
 * effect depend on where the list happens to break (carve-php#757, verdict in
 * markup-carve/carve-js#620).
 */
class AttributeDoesNotCrossItemBoundaryTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testAnAttributeDoesNotReachTheNextItem(): void
    {
        $html = $this->converter->convert("- a\n\n  {.c}\n- b");

        $this->assertSame(
            "<ul>\n  <li><p>a</p></li>\n  <li><p>b</p></li>\n</ul>\n",
            $html,
        );
        $this->assertStringNotContainsString('class="c"', $html);
    }

    public function testAnAttributeStillAttachesWithinItsOwnItem(): void
    {
        // Same item, so the run never crosses a boundary.
        $html = $this->converter->convert("- a\n\n  {.c}\n\n  b");

        $this->assertStringContainsString('<p class="c">b</p>', $html);
    }

    public function testAnAttributeAboveAListIsUnaffected(): void
    {
        $html = $this->converter->convert("{.c}\n- a");

        $this->assertStringContainsString('class="c"', $html);
    }

    public function testAnAttributeAfterAQuoteIsUnaffected(): void
    {
        $html = $this->converter->convert("> a\n\n{.c}\nb");

        $this->assertStringContainsString('<p class="c">b</p>', $html);
    }

    public function testAnAttributeInlineInAnItemIsUnaffected(): void
    {
        $html = $this->converter->convert("- a\n  {.c}\n  b");

        $this->assertStringNotContainsString('<li><p', $html);
    }
}
