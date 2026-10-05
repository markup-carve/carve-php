<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Which finished block at a nested item's bottom folds a flush-left line.
 *
 * Ruled on markup-carve/carve#2734: a flush-left line below a nested item whose
 * lead ends in a closed fence or raw block folds into the OUTER item, and one
 * below a lead ending in a heading, a table or a comment leaves the list. The
 * split is the rule, not a uniform column-0 fold: a uniform fold matches every
 * fence row of the family and then disagrees with the spec on the other kinds,
 * so each arm is the other's control and both are asserted here.
 *
 * Every expectation is measured against the executable spec at the revision
 * `tests/spec` is pinned to (`scripts/spec/layout.mjs` into
 * `scripts/spec/html.mjs`), never read back from this engine.
 */
class AClosedNestedFenceFoldsAColumnZeroLineWhereAHeadingDoesNotTest extends TestCase
{
    private function html(string $source): string
    {
        return trim(preg_replace('/\s+/', ' ', (new CarveConverter())->convert($source)) ?? '');
    }

    public function testAClosedBacktickFenceFoldsTheLineIntoTheOuterItem(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> <pre><code></code></pre> </li> </ul> x </li> </ul>',
            $this->html("- - ```\n    ```\nx\n"),
        );
    }

    public function testAClosedTildeFenceFoldsTheSameWay(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> <pre><code></code></pre> </li> </ul> x </li> </ul>',
            $this->html("- - ~~~\n    ~~~\nx\n"),
        );
    }

    public function testAnInfoStringDoesNotChangeTheArm(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> <pre><code class="language-php"></code></pre> </li> </ul> x </li> </ul>',
            $this->html("- - ``` php\n    ```\nx\n"),
        );
    }

    public function testAClosedRawBlockFoldsTheLineToo(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> </li> </ul> x </li> </ul>',
            $this->html("- - ```=html\n    ```\nx\n"),
        );
    }

    /**
     * The ticket's own shape: the folded line is a fence RUN, and inside the
     * outer item it is an inline verbatim span rather than a second block.
     */
    public function testTheFoldedLineMayItselfBeAFenceRun(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> <pre><code></code></pre> </li> </ul> <code></code> </li> </ul>',
            $this->html("- - ```\n    ```\n```\n"),
        );
    }

    public function testAnOrderedOuterMarkerFoldsTheSameWay(): void
    {
        $this->assertSame(
            '<ol> <li> <ol> <li> <pre><code></code></pre> </li> </ol> x </li> </ol>',
            $this->html("1. 1. ```\n      ```\nx\n"),
        );
    }

    public function testAnIndentedOuterMarkerFoldsTheSameWay(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> <pre><code></code></pre> </li> </ul> x </li> </ul>',
            $this->html("  - - ```\n      ```\nx\n"),
        );
    }

    /**
     * The leave arm. These are the rows a uniform column-0 fold breaks, so they
     * are what proves the fold is keyed on the block kind.
     */
    public function testAHeadingLeavesTheList(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> <h1 id="h">h</h1> </li> </ul> </li> </ul> <p>x</p>',
            $this->html("- - # h\nx\n"),
        );
    }

    public function testACommentLineLeavesTheList(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li></li> </ul> </li> </ul> <p>x</p>',
            $this->html("- - %% c\nx\n"),
        );
    }

    public function testACommentFenceLeavesTheList(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li></li> </ul> </li> </ul> <p>x</p>',
            $this->html("- - %%%\n    c\n    %%%\nx\n"),
        );
    }

    /**
     * The table row is NOT asserted as the ruling's prose states it. At the
     * pinned spec, and at the revision the ruling was measured on, a table of
     * more than one row FOLDS here; the engine already agrees, and both are
     * pinned so a later change to either side is visible.
     */
    public function testATableFoldsAtThePinnedSpec(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> <table> <thead> <tr><th scope="col">a</th></tr> </thead> </table> </li> </ul> x </li> </ul>',
            $this->html("- - | a |\n    | - |\nx\n"),
        );
    }

    /**
     * The item's OWN closed fence is not the nested lead's, so it ends the item
     * and the document takes the line. This is what keeps the fold arm from
     * reaching one container level out.
     */
    public function testAnItemsOwnClosedFenceStillEndsTheItem(): void
    {
        $this->assertSame(
            '<ul> <li> <pre><code></code></pre> </li> </ul> <p>x</p>',
            $this->html("- ```\n  ```\nx\n"),
        );
    }

    public function testAnItemsOwnHeadingStillEndsTheItem(): void
    {
        $this->assertSame(
            '<ul> <li> <h1 id="h">h</h1> </li> </ul> <p>x</p>',
            $this->html("- # h\nx\n"),
        );
    }

    /**
     * §10 lazy continuation is untouched: with a paragraph open the line joins
     * it, in the INNER item, whether or not a closed fence sits above it.
     */
    public function testAnOpenParagraphStillTakesTheLineInTheInnerItem(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li>p x</li> </ul> </li> </ul>',
            $this->html("- - p\nx\n"),
        );
    }

    /**
     * The sibling host. A description body folds the line for a closed nested
     * fence and for a table; it was folding the fence row before this change
     * only because the tracker read the nested lead's fence as unterminated,
     * so the arm is spelled for this collector too.
     */
    public function testADescriptionBodyStillFoldsTheFenceRow(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <ul> <li> <pre><code></code></pre> </li> </ul> <p>x</p> </dd> </dl>',
            $this->html(":: t\n: - ```\n    ```\nx\n"),
        );
    }

    public function testADescriptionBodyStillFoldsTheTableRow(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <ul> <li> <table> <thead> <tr><th scope="col">a</th></tr> </thead> </table> </li> </ul> <p>x</p> </dd> </dl>',
            $this->html(":: t\n: - | a |\n    | - |\nx\n"),
        );
    }
}
