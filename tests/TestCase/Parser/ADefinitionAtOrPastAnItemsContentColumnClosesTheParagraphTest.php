<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition AT OR PAST an item's content column ends the paragraph under it.
 *
 * PART 0's `CARVE-P0-020` AT OR PAST MEANS THE DEEPEST COLUMN THE LINE REACHES
 * (markup-carve/carve#1896) reads the test against the innermost open container
 * whose content column the line REACHES. Past a container's column the line
 * sits at the body's own column 1, still a block position, so a definition
 * written there is a definition - and it interrupts the open paragraph rather
 * than folding a flush-left line in (carve-php#1868).
 *
 * carve-php#1866 moved the COMMENT to that reading and could not carry this:
 * only the comment branch of the trailing-block tracker reads the caller's
 * column flag, while a definition is classified from the line's own bytes with
 * a pattern anchored at offset 0, which the residual column defeated.
 *
 * Measured against the executable spec at markup-carve/carve `95fc3a0`, the
 * revision `tests/spec` is pinned to, which answers every row below identically
 * to `f59cc880`, `caec9ff` and `86569bd` before it; and against carve-js
 * `c552d9f` and carve-rs `eb7091c`. Every provider says which of the four
 * readings its rows match - a cross-engine claim without a revision beside it
 * is a claim nobody can re-check.
 */
class ADefinitionAtOrPastAnItemsContentColumnClosesTheParagraphTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = new CarveConverter();
    }

    /**
     * The item's content column is 2. Column 1 is BELOW it, where §10 I5 makes
     * the definition ordinary text and the flush-left line folds in behind it;
     * 2 is AT it and 3 and 4 are PAST it, and the whole at-or-past half ends the
     * item. Column 0 reaches no container at all.
     *
     * Columns 3 and 4 are the two carve-php answered alone, and all four
     * readings agree on every row.
     *
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function bareItemBandProvider(): array
    {
        return [
            'column 0' => [0, false],
            'below, column 1' => [1, true],
            'at the column' => [2, false],
            'one past the column' => [3, false],
            'two past the column' => [4, false],
        ];
    }

    #[DataProvider('bareItemBandProvider')]
    public function testTheReferenceDefinitionBand(int $column, bool $folds): void
    {
        $html = $this->converter->convert("- a\n" . str_repeat(' ', $column) . "[r]: /url\ntail");

        $this->assertSame(
            $folds
                ? "<ul>\n  <li>a\n[r]: /url\ntail</li>\n</ul>"
                : "<ul>\n  <li>a</li>\n</ul>\n<p>tail</p>",
            trim($html),
        );
    }

    /**
     * The footnote spelling of the same line, over the same band. It is a
     * separate row because a footnote definition is the one invisible block
     * with a BODY, so the tracker records more about it than about a reference
     * definition and a fix could move one without the other.
     *
     * All four readings agree on every row.
     */
    #[DataProvider('bareItemBandProvider')]
    public function testTheFootnoteDefinitionBand(int $column, bool $folds): void
    {
        $html = $this->converter->convert("- a\n" . str_repeat(' ', $column) . "[^f]: x\ntail");

        $this->assertSame(
            $folds
                ? "<ul>\n  <li>a\n[^f]: x\ntail</li>\n</ul>"
                : "<ul>\n  <li>a</li>\n</ul>\n<p>tail</p>",
            trim($html),
        );
    }

    /**
     * CLOSING THE PARAGRAPH IS NOT ALL OF IT. The line has to still BE a
     * definition where it lands, which the corpus sweeps cannot see: their
     * documents end with a blank line and a reference use, and with no open
     * paragraph below the definition there is nothing for the column to close.
     * One document asks both questions at once.
     *
     * Matches all four readings.
     */
    public function testADefinitionPastTheColumnBothEndsTheItemAndRegisters(): void
    {
        $html = $this->converter->convert("- a\n   [r]: /url\ntail\n\nSee [r][].");

        $this->assertSame(
            "<ul>\n  <li>a</li>\n</ul>\n<p>tail</p>\n<p>See <a href=\"/url\">r</a>.</p>",
            trim($html),
        );
    }

    /**
     * WHICH container the line reaches decides who owns it, and that is the
     * half a residual column alone cannot answer. `- - a` opens items at
     * content columns 2 and 4:
     *
     *  - column 3 reaches the OUTER item and not the inner one, so the
     *    definition registers there and ends the outer item;
     *  - column 4 reaches the INNER item, so it registers there instead and
     *    leaves the outer item collecting - the flush-left line lands inside
     *    it, after the sub-list.
     *
     * Reading the residual column without asking which container it reaches
     * ends the outer item at column 4 as well, which is a different document.
     *
     * Rows 0 to 3 match the executable spec and carve-js, with carve-rs the
     * outlier at 0, 2 and 3; rows 4 to 6 match the spec and carve-rs, with
     * carve-js the outlier. carve-php matches the spec on every row.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function markerLeadBandProvider(): array
    {
        $ended = "<ul>\n  <li>\n    <ul>\n      <li>a</li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>";
        $folded = "<ul>\n  <li>\n    <ul>\n      <li>a\n[r]: /url\ntail</li>\n    </ul>\n  </li>\n</ul>";
        $inOuter = "<ul>\n  <li>\n    <ul>\n      <li>a</li>\n    </ul>\n    tail\n  </li>\n</ul>";

        return [
            'column 0' => [0, $ended],
            'below both columns, 1' => [1, $folded],
            'at the outer column' => [2, $ended],
            'past the outer column, below the inner' => [3, $ended],
            'at the inner column' => [4, $inOuter],
            'past the inner column' => [5, $inOuter],
            'two past the inner column' => [6, $inOuter],
        ];
    }

    #[DataProvider('markerLeadBandProvider')]
    public function testTheMarkerLeadBand(int $column, string $expected): void
    {
        $html = $this->converter->convert("- - a\n" . str_repeat(' ', $column) . "[r]: /url\ntail");

        $this->assertSame($expected, trim($html));
    }

    /**
     * The below-the-lead spelling of the same structure. A lead line with the
     * inner marker two columns under it hands the item to a different collector
     * than `- - a` does, and the two routes have answered one document two ways
     * before - the shape carve-php#1857 and carve-php#1864 had to reconcile for
     * a block opener. Pinned side by side so a fix on one route cannot leave the
     * other behind.
     *
     * Rows 0 and 1 match all four readings. Rows 2 and 3 match the spec and
     * carve-js, rows 4 to 6 the spec and carve-rs; carve-php matches the spec on
     * every row.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function subListBandProvider(): array
    {
        $ended = "<ul>\n  <li>a\n    <ul>\n      <li>x</li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>";
        $folded = "<ul>\n  <li>a\n    <ul>\n      <li>x\n[r]: /url\ntail</li>\n    </ul>\n  </li>\n</ul>";
        $inOuter = "<ul>\n  <li>a\n    <ul>\n      <li>x</li>\n    </ul>\n    tail\n  </li>\n</ul>";

        return [
            'column 0' => [0, $ended],
            'below both columns, 1' => [1, $folded],
            'at the outer column' => [2, $ended],
            'past the outer column, below the inner' => [3, $ended],
            'at the inner column' => [4, $inOuter],
            'past the inner column' => [5, $inOuter],
            'two past the inner column' => [6, $inOuter],
        ];
    }

    #[DataProvider('subListBandProvider')]
    public function testTheSubListBand(int $column, string $expected): void
    {
        $html = $this->converter->convert("- a\n  - x\n" . str_repeat(' ', $column) . "[r]: /url\ntail");

        $this->assertSame($expected, trim($html));
    }

    /**
     * THE CONTROL THAT MUST NOT MOVE, ONE. The comment on the identical line
     * reaches its answer through §10 I5's column exemption and carve-php#1866
     * already settled it. The definition band above is not allowed to disturb
     * it: below the column the two kinds part company at column 1, and at or
     * past it they agree.
     *
     * All four readings agree on every row.
     *
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function commentControlProvider(): array
    {
        return [
            'column 0' => [0, true],
            'below, column 1' => [1, true],
            'at the column' => [2, false],
            'one past the column' => [3, false],
            'two past the column' => [4, false],
        ];
    }

    #[DataProvider('commentControlProvider')]
    public function testTheCommentControlIsUnchanged(int $column, bool $folds): void
    {
        $html = $this->converter->convert("- a\n" . str_repeat(' ', $column) . "%% c\ntail");

        $this->assertSame(
            $folds
                ? "<ul>\n  <li>a\n    tail\n  </li>\n</ul>"
                : "<ul>\n  <li>a</li>\n</ul>\n<p>tail</p>",
            trim($html),
        );
    }

    /**
     * THE SAME BAND ON THE DESCRIPTION-BODY HOST. A two-space separator after
     * the description marker puts the body's content column at 3, and carve#956
     * DEFINITION BODIES FOLLOW THE SAME CONTAINER REACH RULE makes it the third
     * indented-block collector - so the band answers the same way the item's
     * does. Columns 4 and 5 were pinned here as a KNOWN DIVERGENCE while
     * carve-php#1868 was measured, and carve-php#1870 flipped them.
     *
     * Rows 0 to 3 match all four readings. Rows 4 and 5 match carve-js and
     * carve-rs; the executable spec kept `tail` inside the body there when this
     * was written, filed as markup-carve/carve#1911 because the oracle
     * contradicted itself - a COMMENT and a HEADING past the same column ended
     * the body in it, and only a definition did not. That is ruled now and the
     * spec at `main` ends the body; the revision `tests/spec` is pinned to
     * predates the ruling.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function descriptionHostProvider(): array
    {
        $ended = "<dl>\n  <dt>t</dt>\n  <dd>a</dd>\n</dl>\n<p>tail</p>";
        $folded = "<dl>\n  <dt>t</dt>\n  <dd>a\n[r]: /url\ntail</dd>\n</dl>";

        return [
            'column 0' => [0, $ended],
            'below the column, 1' => [1, $folded],
            'below the column, 2' => [2, $folded],
            'at the column' => [3, $ended],
            'one past the column' => [4, $ended],
            'two past the column' => [5, $ended],
        ];
    }

    #[DataProvider('descriptionHostProvider')]
    public function testTheDescriptionHostEndsTheBodyToo(int $column, string $expected): void
    {
        $html = $this->converter->convert(":: t\n:  a\n" . str_repeat(' ', $column) . "[r]: /url\ntail");

        $this->assertSame($expected, trim($html));
    }
}
