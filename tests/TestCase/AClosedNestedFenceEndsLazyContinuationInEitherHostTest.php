<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A closed block leaves no paragraph at the deepest frame in either host
 * (markup-carve/carve#2884 and markup-carve/carve#2895).
 */
class AClosedNestedFenceEndsLazyContinuationInEitherHostTest extends TestCase
{
    private function html(string $source): string
    {
        return trim(preg_replace('/\s+/', ' ', (new CarveConverter())->convert($source)) ?? '');
    }

    public function testAClosedBacktickFenceEndsTheItem(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> <pre><code></code></pre> </li> </ul> </li> </ul> <p>x</p>',
            $this->html("- - ```\n    ```\nx\n"),
        );
    }

    public function testAClosedTildeFenceEndsTheItem(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> <pre><code></code></pre> </li> </ul> </li> </ul> <p>x</p>',
            $this->html("- - ~~~\n    ~~~\nx\n"),
        );
    }

    public function testAnInfoStringDoesNotChangeTheArm(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> <pre><code class="language-php"></code></pre> </li> </ul> </li> </ul> <p>x</p>',
            $this->html("- - ``` php\n    ```\nx\n"),
        );
    }

    public function testAClosedRawBlockEndsTheItem(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> </li> </ul> </li> </ul> <p>x</p>',
            $this->html("- - ```=html\n    ```\nx\n"),
        );
    }

    public function testAFenceFollowerOpensOutsideTheList(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> <pre><code></code></pre> </li> </ul> </li> </ul> <pre><code></code></pre>',
            $this->html("- - ```\n    ```\n```\n"),
        );
    }

    public function testAnOrderedOuterMarkerEndsTheSameWay(): void
    {
        $this->assertSame(
            '<ol> <li> <ol> <li> <pre><code></code></pre> </li> </ol> </li> </ol> <p>x</p>',
            $this->html("1. 1. ```\n      ```\nx\n"),
        );
    }

    public function testAnIndentedOuterMarkerEndsTheSameWay(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> <pre><code></code></pre> </li> </ul> </li> </ul> <p>x</p>',
            $this->html("  - - ```\n      ```\nx\n"),
        );
    }

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

    public function testATableLeavesTheList(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> <table> <thead> <tr><th scope="col">a</th></tr> </thead> </table> </li> </ul> </li> </ul> <p>x</p>',
            $this->html("- - | a |\n    | - |\nx\n"),
        );
    }

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

    public function testAnOpenParagraphStillTakesTheLineInTheInnerItem(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li>p x</li> </ul> </li> </ul>',
            $this->html("- - p\nx\n"),
        );
    }

    public function testADescriptionBodyEndsAfterTheFence(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <ul> <li> <pre><code></code></pre> </li> </ul> </dd> </dl> <p>x</p>',
            $this->html(":: t\n: - ```\n    ```\nx\n"),
        );
    }

    public function testADescriptionBodyEndsAfterTheTable(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <ul> <li> <table> <thead> <tr><th scope="col">a</th></tr> </thead> </table> </li> </ul> </dd> </dl> <p>x</p>',
            $this->html(":: t\n: - | a |\n    | - |\nx\n"),
        );
    }
}
