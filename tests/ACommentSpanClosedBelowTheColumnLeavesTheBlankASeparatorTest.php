<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A COMMENT FENCE'S CLOSER IS THE SAME DELIMITER AT EVERY COLUMN (PART 9 §28,
 * markup-carve/carve#2471), so a closer written BELOW an item's content column
 * still ends the span - and the blank under it is then the separator §17 L1
 * decides looseness from.
 *
 * The item collector advanced its comment-fence tracker only for lines AT or
 * PAST the content column. A closer below it took the lazy branch, which left
 * the tracker latched, so the blank line under the closer read as fence payload
 * and was collected into the item. The outer list loop never saw a blank, and
 * the item came out TIGHT where the oracle, carve-js and carve-rs all answer
 * LOOSE (markup-carve/carve-php#2627).
 *
 * The closer's column is the only parameter: the same document with the closer
 * at the content column, or past it, already answered LOOSE, which is what made
 * the defect look like a spelling rather than a bug.
 *
 * WHAT MAKES EACH GUARD ABLE TO FAIL. The fix opens a span the collector used
 * to hold open, so every over-correction shows up as a blank that loosens when
 * it must not: a blank INSIDE the span is payload (markup-carve/carve#985), an
 * item with no blank below the closer has nothing to separate, and an opener
 * with no closer ahead opens no span at all and must not latch the tracker.
 * Expectations were taken from the oracle (scripts/spec/layout.mjs plus
 * scripts/spec/html.mjs) at markup-carve/carve 38829a97, run per row.
 */
class ACommentSpanClosedBelowTheColumnLeavesTheBlankASeparatorTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    /**
     * Each of these renders a tight item before the fix.
     *
     * @return array<string, array{string, string}>
     */
    public static function theBlankBelowTheCloserSeparates(): array
    {
        return [
            'closer at column 0' => [
                "- head\n  %%%\n  a\n%%%\n\n  tail\n",
                "<ul>\n  <li><p>head</p>\n    <p>tail</p>\n  </li>\n</ul>\n",
            ],
            // The opener establishes its own authored base past the content
            // column (markup-carve/carve#1705), and the closer is still the
            // same delimiter.
            'opener past the column, closer at column 0' => [
                "- head\n   %%%\n   a\n%%%\n\n  tail\n",
                "<ul>\n  <li><p>head</p>\n    <p>tail</p>\n  </li>\n</ul>\n",
            ],
            // The rule is the delimiter's column, not the bullet.
            'an ordered item answers the same' => [
                "1. head\n   %%%\n   a\n%%%\n\n   tail\n",
                "<ol>\n  <li><p>head</p>\n    <p>tail</p>\n  </li>\n</ol>\n",
            ],
        ];
    }

    #[DataProvider('theBlankBelowTheCloserSeparates')]
    public function testABelowColumnCloserLeavesTheBlankASeparator(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($source));
    }

    /**
     * Rows that already answered correctly and must not move.
     *
     * @return array<string, array{string, string}>
     */
    public static function unmovedByTheFix(): array
    {
        return [
            'closer at the content column' => [
                "- head\n  %%%\n  a\n  %%%\n\n  tail\n",
                "<ul>\n  <li><p>head</p>\n    <p>tail</p>\n  </li>\n</ul>\n",
            ],
            'closer past the content column' => [
                "- head\n  %%%\n  a\n    %%%\n\n  tail\n",
                "<ul>\n  <li><p>head</p>\n    <p>tail</p>\n  </li>\n</ul>\n",
            ],
            // Nothing separates two blocks here, so the item is tight.
            'no blank below the column-0 closer' => [
                "- head\n  %%%\n  a\n%%%\n  tail\n",
                "<ul>\n  <li>head\n    tail\n  </li>\n</ul>\n",
            ],
            // markup-carve/carve#985: the only blank sits INSIDE the span, so
            // it is payload and the item stays tight.
            'a blank inside the span, closer at column 0' => [
                "- head\n  %%%\n  a\n\n  b\n%%%\n  tail\n",
                "<ul>\n  <li>head\n    tail\n  </li>\n</ul>\n",
            ],
            // §28: an opener with no closer ahead opens NOTHING and is one
            // `%%` line comment. Loose here because the blanks are ordinary
            // separators, not because a span was closed.
            'an unterminated opener latches nothing' => [
                "- head\n  %%%\n  a\n\n  tail\n",
                "<ul>\n  <li><p>head</p>\n    <p>a</p>\n    <p>tail</p>\n  </li>\n</ul>\n",
            ],
            'the line-comment spelling is untouched' => [
                "- head\n  %%\n  a\n\n  tail\n",
                "<ul>\n  <li><p>head</p>\n    <p>a</p>\n    <p>tail</p>\n  </li>\n</ul>\n",
            ],
        ];
    }

    #[DataProvider('unmovedByTheFix')]
    public function testTheRowsAroundTheFixDoNotMove(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($source));
    }
}
