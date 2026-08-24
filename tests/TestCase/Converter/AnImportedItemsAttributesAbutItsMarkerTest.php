<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * An imported `<li>`'s attributes abut its marker.
 *
 * They used to be written on an indented line BELOW the marker. A brace pair on
 * a line of its own is a BLOCK attribute, and a block attribute floats FORWARD,
 * so it never landed on the item: on a one-block item it floated off the end of
 * the item and left the document, and on a two-block item it attached to the
 * SECOND block instead. A degraded footnote lost its `id` that way.
 *
 * The floating itself is correct and is not what changed. What changed is that
 * the attribute is no longer put somewhere it can float away from: `1.{#fn1} n`
 * is the spelling carve-js writes, and the abutting shape was already in this
 * writer for an item whose only content is a nested list - it simply was not
 * reached on any other path.
 */
class AnImportedItemsAttributesAbutItsMarkerTest extends TestCase
{
    protected function import(string $html): string
    {
        return (new HtmlToCarve())->convert($html);
    }

    protected function html(string $carve): string
    {
        return (new CarveConverter())->convert($carve);
    }

    /**
     * The shape the ticket names, with no endnotes involved at all.
     */
    public function testADegradedNoteKeepsItsIdOnTheItem(): void
    {
        $carve = $this->import('<section><hr><ol><li id="fn1">n</li></ol></section>');

        $this->assertSame("---\n\n1.{#fn1} n\n", $carve);
        $this->assertStringContainsString('<li id="fn1">', $this->html($carve));
    }

    /**
     * A two-block item is where the old spelling did not merely lose the
     * attribute but MISPLACED it: the brace line sat between the item's first
     * and second block, so it floated onto the second paragraph.
     */
    public function testATwoBlockItemPutsTheIdOnTheItemAndNotOnItsSecondBlock(): void
    {
        $carve = $this->import('<ol><li id="fn1"><p>a</p><p>b</p></li></ol>');

        $this->assertSame("1.{#fn1} a\n\n   b\n", $carve);

        $html = $this->html($carve);
        $this->assertStringContainsString('<li id="fn1">', $html);
        $this->assertStringNotContainsString('<p id="fn1">', $html);
    }

    /**
     * Attributes are metadata, so the ordered marker alone fixes the content
     * column regardless of the attribute spelling.
     */
    public function testTheContentColumnUsesTheBareMarker(): void
    {
        $carve = $this->import('<ol><li id="fn1"><p>a</p><p>b</p></li></ol>');
        $lines = explode("\n", $carve);

        $this->assertSame(strlen('1. '), strlen($lines[2]) - strlen(ltrim($lines[2])));
    }

    /**
     * A CHECKBOX IS CONTENT, NOT MARKER, so the attributes go ahead of it.
     * `- [x]{#t} a` would parse as a span carrying the id around the letter
     * `x`, which is a different document.
     */
    public function testATaskItemTakesItsAttributesAheadOfTheCheckbox(): void
    {
        $carve = $this->import(
            '<ul class="task-list"><li id="t"><input type="checkbox" checked>a</li></ul>',
        );

        $this->assertSame("-{#t} [x] a\n", $carve);

        $html = $this->html($carve);
        $this->assertStringContainsString('<li id="t">', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
    }

    /**
     * An EMPTY item needs something after the brace pair: a line ending in
     * `-{#x}` is not a marker, and comes back as a paragraph reading the braces
     * as a tag span. `+` is the continuation marker, and it is what carve-js
     * writes here too.
     */
    public function testAnEmptyItemKeepsItsIdBehindTheContinuationMarker(): void
    {
        $carve = $this->import('<ul><li id="x"></li></ul>');

        $this->assertSame("-{#x} +\n", $carve);
        $this->assertStringContainsString('<li id="x">', $this->html($carve));
    }

    /**
     * The path that already had the abutting spelling keeps it, and keeps its
     * nested list inside the item.
     */
    public function testAnItemHoldingOnlyANestedListStillAbutsAndStaysNested(): void
    {
        $carve = $this->import('<ul><li id="o"><ul><li>a</li><li>b</li></ul></li></ul>');

        $this->assertSame("-{#o} - a\n  - b\n", $carve);

        $html = $this->html($carve);
        $this->assertStringContainsString('<li id="o">', $html);
        // Two items, both still INSIDE the outer one - the sublist did not
        // split off into a list of its own.
        $this->assertSame(2, substr_count($html, '<ul>'));
        $this->assertStringContainsString('<li>b</li>', $html);
    }

    /**
     * A sublist below the item's own text reaches the bare bullet's column 2;
     * the item attributes do not alter its containment.
     */
    public function testASublistBelowTheItemsTextReachesTheBareMarkerColumn(): void
    {
        $carve = $this->import('<ul><li id="o">a<ul><li>b</li></ul></li></ul>');

        $this->assertSame("-{#o} a\n\n  - b\n", $carve);
        $this->assertStringContainsString('<li id="o">a', $this->html($carve));
        $this->assertSame(2, substr_count($this->html($carve), '<ul>'));
    }

    /**
     * A QUOTED VALUE MAY HOLD AN ESCAPED QUOTE. The marker's attribute pattern
     * spelled the quoted value `"[^"]*"`, which ends at the backslash's own
     * quote, so the whole marker matched nothing and the line came back a
     * paragraph - while the SAME value in a block attribute parsed, and
     * carve-js reads the marker form. Writing an item's attributes on the
     * marker is what puts an editor export's `title` in front of that pattern.
     */
    public function testAQuotedAttributeValueMayHoldAnEscapedQuote(): void
    {
        $carve = $this->import('<ul><li title="a&quot;b">x</li></ul>');

        $this->assertSame("-{title=\"a\\\"b\"} x\n", $carve);
        $this->assertStringContainsString('<li title="a&quot;b">', $this->html($carve));
    }

    /**
     * A brace inside a quoted value is content, not the end of the block. The
     * emitted-source normalizer read the pair as `[^{}]*`, so it ended the
     * block at the `}` in the title, failed to see a marker, closed the list
     * context and flattened the item's second block to column zero.
     */
    public function testABraceInsideAQuotedValueDoesNotEndTheAttributeBlock(): void
    {
        $carve = $this->import('<ol><li title="a}b"><p>x</p><p>y</p></li></ol>');

        $this->assertSame("1.{title=\"a}b\"} x\n\n   y\n", $carve);

        $html = $this->html($carve);
        $this->assertStringContainsString('<li title="a}b">', $html);
        $this->assertStringContainsString('<p>y</p>', $html);
    }

    /**
     * An item with no attributes at all is untouched: the marker keeps its
     * plain shape and the content column stays where it was.
     */
    public function testAnUnattributedItemIsUnchanged(): void
    {
        $this->assertSame("1. a\n\n   b\n", $this->import('<ol><li><p>a</p><p>b</p></li></ol>'));
        $this->assertSame("- [x] a\n", $this->import('<ul class="task-list"><li><input type="checkbox" checked>a</li></ul>'));
    }
}
