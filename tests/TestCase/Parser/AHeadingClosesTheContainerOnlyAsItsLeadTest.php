<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A heading ends the container it OPENS, and only that one.
 *
 * PART 1 S4 (*NO OPEN PARAGRAPH, NO LAZY LINE*) decides whether a column-0 line
 * folds into the container above it, and for most closed blocks the answer is
 * flat: a table, a fenced body, a thematic break, a definition and a floating
 * attribute all leave nothing to fold into, wherever they are written. A
 * HEADING is the one block whose answer depends on where it sits, and the pinned
 * corpus states both halves:
 *
 * ```
 * - # H
 * tail
 * ```
 *
 * Corpus 326: `tail` is a NEW TOP-LEVEL BLOCK.
 *
 * ```
 * - a
 *   - b
 *     # N
 * lazy
 * ```
 *
 * Corpus 75-4: `lazy` FOLDS INTO THE ITEM.
 *
 * An item whose LEAD is a heading never held a paragraph, so there is nothing
 * for the line under it to continue. An item that leads with text still IS a
 * paragraph, and a heading written beneath it does not end the item.
 *
 * WHY THIS FILE EXISTS RATHER THAN THE CORPUS ALONE. The corpus pins the two
 * rows above and both of them reach the tracker through the SAME seeding site -
 * the marker line. The item's POST-BLANK content is collected by a second
 * site with its own tracker, and that one starts fresh: read as a lead, the
 * first heading after an internal blank closed an item that plainly still holds
 * its text. No corpus document has that shape, so removing the fix left the
 * whole suite green - a mutation that survived, which is what these rows are
 * for. carve-rs renders every row below as asserted.
 */
class AHeadingClosesTheContainerOnlyAsItsLeadTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * The lead spelling: the item opens with the heading and closes with it.
     */
    public function testAHeadingOnTheMarkerLineEndsTheItem(): void
    {
        $html = $this->html("- # H\ntail\n");

        $this->assertStringContainsString("</li>\n</ul>\n<p>tail</p>", $html, $html);
    }

    /**
     * The same heading one line lower, under text, keeps the item open.
     */
    public function testAHeadingUnderTheItemSTextDoesNotEndIt(): void
    {
        $html = $this->html("- a\n  # H\ntail\n");

        $this->assertStringContainsString("<h1 id=\"H\">H</h1>\n    tail", $html, $html);
    }

    /**
     * THE SURVIVING-MUTANT ROW. An internal blank sends the rest of the item to
     * a second collector with a tracker of its own, and the heading after the
     * blank is that stream's first line without being the ITEM's lead.
     */
    public function testAHeadingAfterAnInternalBlankIsNotTheItemSLead(): void
    {
        $html = $this->html("- text\n\n  # N\nlazy\n");

        $this->assertStringContainsString("<h1 id=\"N\">N</h1>\n    lazy", $html, $html);
    }

    /**
     * The pair of the row above: even an item that DOES lead with a heading has
     * a lead only once, so a second heading after the blank is not one.
     */
    public function testOnlyTheFirstHeadingCanBeTheLead(): void
    {
        $html = $this->html("- # A\n\n  # N\nlazy\n");

        $this->assertStringContainsString("<h1 id=\"N\">N</h1>\n    lazy", $html, $html);
    }

    /**
     * A QUOTE IS ASKED THE SAME QUESTION ONE LEVEL IN, so the lead of the quote
     * is what decides - not the lead of the item holding it. `- a` still holds
     * a paragraph, and `tail` leaves anyway, because the quote it would have to
     * cross holds only a heading (corpus 326-11 is the marker-line spelling).
     */
    public function testTheQuoteSOwnLeadDecidesNotTheItemS(): void
    {
        $html = $this->html("- a\n  > # H\ntail\n");

        $this->assertStringContainsString("</li>\n</ul>\n<p>tail</p>", $html, $html);
    }

    /**
     * And a quote that leads with prose still folds, which is what makes the
     * row above a rule rather than "quotes end items".
     */
    public function testAQuoteLeadingWithProseStillFolds(): void
    {
        $html = $this->html("- a\n  > q\ntail\n");

        $this->assertStringContainsString("q\ntail", $html, $html);
    }
}
