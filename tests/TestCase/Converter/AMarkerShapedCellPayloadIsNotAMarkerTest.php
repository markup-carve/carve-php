<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A cell whose whole payload is a span marker is escaped, and a real span is not.
 *
 * PADDING IS NOT AN ESCAPE WHERE THE PRODUCTION ADMITS PADDING (PART 11 §6f,
 * markup-carve/carve#1601). §6e's one space in front of a cell's content puts
 * it out of reach of the three slots read GLUED to the opening pipe, and that
 * argument holds only where the construct forbids the padding. The span cell is
 * written WITH the padding inside it - `rowspan_marker = {space}, '^', {space}`
 * - so a cell whose whole payload is `^` or `<` re-reads as a span however it
 * is padded, and §2 is what applies.
 *
 * WHAT IT COSTS IS THE CELL, not a byte of spelling, which is why this is a §1
 * failure rather than an under-escaped character: the row above grows a
 * `rowspan` nobody wrote and the caret's own cell is deleted outright.
 *
 * THE OTHER DIRECTION IS THE ONE WITH NO FIXTURE. The `^` and `<` the importer
 * writes for a real `rowspan` / `colspan` are markers on purpose, and escaping
 * those would destroy the span the HTML actually held - so a rule stated over
 * the emitted characters rather than over their origin would fix this defect by
 * introducing a worse one. The shared contract fixture
 * `html-import/marker-shaped-cell` pins the content half only.
 */
class AMarkerShapedCellPayloadIsNotAMarkerTest extends TestCase
{
    public function testAMarkerShapedPayloadIsEscaped(): void
    {
        $carve = (new HtmlToCarve())->convert(
            '<table><tr><td>a</td><td>b</td></tr><tr><td>^</td><td>&lt;</td></tr></table>',
        );

        $this->assertSame("| a | b |\n| \\^ | \\< |", trim($carve));
    }

    /**
     * And it round-trips: the cell comes back holding the character it held.
     */
    public function testTheEscapedCellIsAFixedPoint(): void
    {
        $html = '<table><tr><td>a</td><td>b</td></tr><tr><td>^</td><td>&lt;</td></tr></table>';
        $carve = (new HtmlToCarve())->convert($html);
        $rendered = (new CarveConverter())->convert($carve);

        $this->assertStringContainsString('<td>^</td>', $rendered);
        $this->assertStringContainsString('<td>&lt;</td>', $rendered);
        $this->assertStringNotContainsString('rowspan', $rendered);
        $this->assertStringNotContainsString('colspan', $rendered);

        $this->assertSame($carve, (new HtmlToCarve())->convert($rendered));
    }

    /**
     * A REAL rowspan still writes the bare marker it needs.
     */
    public function testARealRowspanKeepsItsBareMarker(): void
    {
        $carve = (new HtmlToCarve())->convert(
            '<table><tr><td rowspan="2">a</td><td>b</td></tr><tr><td>d</td></tr></table>',
        );

        $this->assertSame("| a | b |\n| ^ | d |", trim($carve));
        $this->assertStringContainsString(
            'rowspan="2"',
            (new CarveConverter())->convert($carve),
        );
    }

    /**
     * A REAL colspan does too.
     */
    public function testARealColspanKeepsItsBareMarker(): void
    {
        $carve = (new HtmlToCarve())->convert(
            '<table><tr><td colspan="2">a</td></tr><tr><td>c</td><td>d</td></tr></table>',
        );

        $this->assertSame("| a | < |\n| c | d |", trim($carve));
        $this->assertStringContainsString(
            'colspan="2"',
            (new CarveConverter())->convert($carve),
        );
    }

    /**
     * An ATTRIBUTED cell is not asked, for the reason the parser does not ask
     * it: an attribute block ahead of the payload already makes the cell
     * content, so an escape there would be one §2 does not want.
     */
    public function testAnAttributedMarkerShapedCellIsLeftAlone(): void
    {
        $carve = (new HtmlToCarve())->convert(
            '<table><tr><td>a</td><td>b</td></tr><tr><td id="x">^</td><td>d</td></tr></table>',
        );

        $this->assertSame("| a | b |\n|{#x} ^ | d |", trim($carve));
        $this->assertStringContainsString(
            '<td id="x">^</td>',
            (new CarveConverter())->convert($carve),
        );
    }
}
