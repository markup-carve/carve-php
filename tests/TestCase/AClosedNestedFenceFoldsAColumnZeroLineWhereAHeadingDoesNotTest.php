<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Which finished block at a nested item's bottom folds a flush-left line.
 *
 * markup-carve/carve#2734 ruled a SPLIT here: a closed fence or raw block folds
 * the line into the OUTER item, a heading, a table or a comment leaves the list.
 * markup-carve/carve#2884 RETIRES that split. The rule is one sentence - a
 * below-column line continues a paragraph if and only if one is open at the
 * deepest frame - and a closed fence holds no more paragraph than a heading
 * does, so every row of this family closes. The table row below was #2734's
 * and has moved with the ruling.
 *
 * TWO ROWS STILL FOLD IN THIS ENGINE, knowingly. Its marker-walk arm reports
 * nothing when the recursion ends on a fence, because an UNFINISHED opener must
 * stay prose, so the item never learns the fence closed. The oracle and carve-js
 * close them. Tracked at markup-carve/carve#2895, with the body-line spelling
 * (a fence opened on a BODY line rather than on the marker) already correct here.
 *
 * Every expectation is measured against the executable spec at the revision
 * `tests/spec` is pinned to (`scripts/spec/layout.mjs` into
 * `scripts/spec/html.mjs`), never read back from this engine - except the rows
 * named above, which record this engine's divergence on purpose.
 */
class AClosedNestedFenceFoldsAColumnZeroLineWhereAHeadingDoesNotTest extends TestCase
{
    private function html(string $source): string
    {
        return trim(preg_replace('/\s+/', ' ', (new CarveConverter())->convert($source)) ?? '');
    }

    /**
     * STILL A FOLD IN THIS ENGINE ONLY (markup-carve/carve#2895). The oracle and
     * carve-js close here under #2884's rule; this engine's marker-walk arm
     * cannot see that the fence closed. The four rows below it are the same
     * shape and the same divergence.
     */
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
     * #2734 pinned this as a FOLD, on the ground that the spec folded it then.
     * markup-carve/carve#2884 reverses that: a table leaves no paragraph open at
     * the deepest frame, so the line closes the list exactly as it does after a
     * heading. The method name carried the old reading and has moved with it.
     */
    public function testATableLeavesTheList(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> <table> <thead> <tr><th scope="col">a</th></tr> </thead> </table> </li> </ul> </li> </ul> <p>x</p>',
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
