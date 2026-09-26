<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `+` FORWARDED INTO A NESTED ITEM STREAM CARRIES ITS OWN COLUMN
 * (`CARVE-P9-031`, markup-carve/carve#2322).
 *
 * The nested collector forwards a lazy line by stripping its indentation, so an
 * unsatisfied `+` needs a residual column or the nested parse reads it as the
 * nested list's own marker and the character never reaches the output. One
 * FIXED column is that residual only while the item's content column is two:
 * under `1.` the content column is three, the nested marker sits one column in,
 * and a single column aliases it exactly as document column 1 aliased the
 * sublist's marker column in markup-carve/carve-php#2461.
 *
 * THE COLUMN TABLE IS THE EVIDENCE. `ZZZ` in the marker's slot survives every
 * column, so a row that loses the `+` alone is about the marker.
 */
class AForwardedContinuationMarkerKeepsItsColumnTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    public function testAMarkerBelowAnOrderedItemSSublistReachesTheOutput(): void
    {
        $html = $this->converter->convert("1. x\n    - M\n  +\n");

        $this->assertSame(
            "<ol>\n  <li>x\n    <ul>\n      <li>M\n+</li>\n    </ul>\n  </li>\n</ol>\n",
            $html,
        );
    }

    public function testAMarkerBelowATabIndentedSublistReachesTheOutput(): void
    {
        $html = $this->converter->convert("1. x\n\t- L\n  +\n");

        $this->assertStringContainsString('+', $html);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function columnProvider(): array
    {
        $rows = [];
        foreach ([0, 1, 2, 3, 4, 5] as $column) {
            $rows['column ' . $column] = [str_repeat(' ', $column)];
        }

        return $rows;
    }

    #[DataProvider('columnProvider')]
    public function testOrdinaryTextInTheMarkerSSlotSurvivesEveryColumn(string $pad): void
    {
        $html = $this->converter->convert("1. x\n    - M\n{$pad}ZZZ\n");

        $this->assertStringContainsString('ZZZ', $html);
    }

    /**
     * PAST A BLANK LINE THE MARKER HAS LEFT THE ITEM. Forwarding it into the
     * nested stream put it inside the item, loosened the item that no blank
     * followed, and split the line below it off into its own paragraph.
     */
    public function testAMarkerPastABlankLineStaysOutsideTheItem(): void
    {
        $html = $this->converter->convert("- x\n  - L\n\n +\n");

        $this->assertSame(
            "<ul>\n  <li>x\n    <ul>\n      <li>L</li>\n    </ul>\n  </li>\n</ul>\n<p>+</p>\n",
            $html,
        );
    }

    public function testAMarkerPastABlankLineKeepsTheLineBelowInItsParagraph(): void
    {
        $html = $this->converter->convert("- x\n  - L\n\n +\nfollow\n");

        $this->assertSame(
            "<ul>\n  <li>x\n    <ul>\n      <li>L</li>\n    </ul>\n  </li>\n</ul>\n<p>+\nfollow</p>\n",
            $html,
        );
    }
}
