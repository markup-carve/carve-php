<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A comment AT OR PAST an item's content column ends the paragraph under it.
 *
 * PART 9 §24 C3 states the rule over "AT OR PAST": the content column is the
 * container body's canonical column 0, so a line one column past it sits at the
 * body's own column 1 - still a block position inside the body. This engine
 * applied it at the column EXACTLY, so a comment written one space deeper left
 * the paragraph open and folded a flush-left line into the item
 * (carve-php#1866).
 *
 * PART 9 §10 I5's first exception is what makes the comment the kind that
 * reaches the question at all: "A COMMENT IS COLUMN-EXEMPT ... Below a
 * container's content column a comment is still invisible and still closes the
 * paragraph. The other four kinds are ordinary text there". The definition
 * control at the bottom is the other half of that sentence: BELOW the column it
 * still folds as text, which is where comment and definition part company. AT
 * OR PAST the column they answer alike - a definition is a block there too, and
 * carve-php#1868 is where this engine caught up.
 *
 * Measured against the executable spec at markup-carve/carve `caec9ff`,
 * carve-js at `c552d9f` and carve-rs at `eb7091c`. Every row below states which
 * of the four readings it covers; a claim without a revision beside it is a
 * claim nobody can re-check.
 */
class ACommentAtOrPastAnItemsContentColumnClosesTheParagraphTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new CarveConverter();
    }

    /**
     * The item's content column is 2. Columns 0 and 1 are BELOW it, where the
     * comment adds no block and the following line folds; 2 is AT it and 3 and
     * 4 are PAST it, and the whole at-or-past half ends the item.
     *
     * All four readings agree on every row.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function bareItemBandProvider(): array
    {
        $folded = "<ul>\n  <li>a\n    tail\n  </li>\n</ul>";
        $ended = "<ul>\n  <li>a</li>\n</ul>\n<p>tail</p>";

        return [
            'below, column 0' => [0, $folded],
            'below, column 1' => [1, $folded],
            'at the column' => [2, $ended],
            'one past the column' => [3, $ended],
            'two past the column' => [4, $ended],
        ];
    }

    #[DataProvider('bareItemBandProvider')]
    public function testTheBareItemBand(int $column, string $expected): void
    {
        $html = $this->converter->convert("- a\n" . str_repeat(' ', $column) . "%% c\ntail");

        $this->assertSame($expected, trim($html));
    }

    /**
     * The same rule with a sub-list between the lead and the comment. The outer
     * item's content column is 2 and the inner item's is 4, so columns 2 to 6
     * are all at or past a content column and end the list.
     *
     * The engine used to end it only where the column matched a content column
     * EXACTLY, which is why `tail` climbed one level at column 4 and dropped
     * back at 5 - the shape that showed the defect is not an off-by-one at one
     * boundary (carve-php#1866).
     *
     * Rows 0 and 1 match the executable spec and carve-js; carve-rs answers
     * those two differently and that divergence is not this rule. Rows 2 to 6
     * match all four readings.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function subListBandProvider(): array
    {
        $folded = "<ul>\n  <li>a\n    <ul>\n      <li>x\n        tail\n      </li>\n    </ul>\n  </li>\n</ul>";
        $ended = "<ul>\n  <li>a\n    <ul>\n      <li>x</li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>";

        return [
            'below both columns, 0' => [0, $folded],
            'below both columns, 1' => [1, $folded],
            'at the outer column' => [2, $ended],
            'past the outer column' => [3, $ended],
            'at the inner column' => [4, $ended],
            'past the inner column' => [5, $ended],
            'two past the inner column' => [6, $ended],
        ];
    }

    #[DataProvider('subListBandProvider')]
    public function testTheSubListBand(int $column, string $expected): void
    {
        $html = $this->converter->convert("- a\n  - x\n" . str_repeat(' ', $column) . "%% c\ntail");

        $this->assertSame($expected, trim($html));
    }

    /**
     * The marker-lead spelling of the same structure. `- - a` hands the item to
     * a different collector than the below-the-lead spelling does, and the two
     * routes answered the same document differently - the shape carve-php#1857
     * and carve-php#1864 had to reconcile for a block opener. Pinned side by
     * side so a fix on one route cannot leave the other behind.
     *
     * Rows 0 and 2 to 4 match all four readings; row 1 matches the executable
     * spec and carve-js, with carve-rs the outlier as in the band above.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function markerLeadBandProvider(): array
    {
        $folded = "<ul>\n  <li>\n    <ul>\n      <li>a\n        tail\n      </li>\n    </ul>\n  </li>\n</ul>";
        $ended = "<ul>\n  <li>\n    <ul>\n      <li>a</li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>";

        return [
            'below the outer column, 0' => [0, $folded],
            'below the outer column, 1' => [1, $folded],
            'at the outer column' => [2, $ended],
            'past the outer column' => [3, $ended],
            'at the inner column' => [4, $ended],
        ];
    }

    #[DataProvider('markerLeadBandProvider')]
    public function testTheMarkerLeadBand(int $column, string $expected): void
    {
        $html = $this->converter->convert("- - a\n" . str_repeat(' ', $column) . "%% c\ntail");

        $this->assertSame($expected, trim($html));
    }

    /**
     * THE HOST THAT MUST NOT MOVE. The definition-description host already
     * answered the whole band the agreed way, at every column, so the fix is
     * scoped to the item collectors rather than to the shared tracker's
     * contract. All four readings agree on every row.
     *
     * @return array<string, array{0: int}>
     */
    public static function descriptionBandProvider(): array
    {
        return [
            'column 0' => [0],
            'column 1' => [1],
            'column 2' => [2],
            'column 3' => [3],
            'column 4' => [4],
            'column 5' => [5],
        ];
    }

    #[DataProvider('descriptionBandProvider')]
    public function testTheDescriptionHostIsUnchangedAcrossTheBand(int $column): void
    {
        $html = $this->converter->convert(":: t\n:  a\n" . str_repeat(' ', $column) . "%% c\ntail");

        $this->assertSame("<dl>\n  <dt>t</dt>\n  <dd>a</dd>\n</dl>\n<p>tail</p>", trim($html));
    }

    /**
     * THE DISCRIMINATING CONTROL. §10 I5 exempts the comment and nothing else:
     * below the content column a definition on the identical line is ordinary
     * text and folds into the item, comment and definition parting company at
     * column 1. Matches all four readings.
     */
    public function testADefinitionBelowTheColumnStillFoldsAsText(): void
    {
        $html = $this->converter->convert("- a\n [r]: /url\ntail");

        $this->assertSame("<ul>\n  <li>a\n[r]: /url\ntail</li>\n</ul>", trim($html));
    }

    /**
     * And at the column it is a block like any other, so it ends the item
     * exactly as the comment does. Matches all four readings.
     */
    public function testADefinitionAtTheColumnEndsTheItem(): void
    {
        $html = $this->converter->convert("- a\n  [r]: /url\ntail");

        $this->assertSame("<ul>\n  <li>a</li>\n</ul>\n<p>tail</p>", trim($html));
    }

    /**
     * PAST the column it is a block as well, and this is the row carve-php#1868
     * moved. The engine used to keep the paragraph open here while the other
     * three readings ended the item, because the comment reaches its answer
     * through §10 I5's exemption while a definition is classified from the
     * line's own bytes - and the residual column defeated the match.
     *
     * Now matches all four readings, at the revisions named on the class.
     * `ADefinitionAtOrPastAnItemsContentColumnClosesTheParagraphTest` carries
     * the rest of that band, nesting included.
     *
     * @return array<string, array{0: int}>
     */
    public static function definitionPastTheColumnProvider(): array
    {
        return [
            'one past the column' => [3],
            'two past the column' => [4],
        ];
    }

    #[DataProvider('definitionPastTheColumnProvider')]
    public function testADefinitionPastTheColumnEndsTheItem(int $column): void
    {
        $html = $this->converter->convert("- a\n" . str_repeat(' ', $column) . "[r]: /url\ntail");

        $this->assertSame("<ul>\n  <li>a</li>\n</ul>\n<p>tail</p>", trim($html));
    }
}
