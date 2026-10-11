<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A description body uses the deepest frame's paragraph state for lazy
 * continuation (markup-carve/carve#2884 and markup-carve/carve#2895).
 */
class ADescriptionBodyEndsAfterAClosedBlockInTheDeepestFrameTest extends TestCase
{
    private function html(string $source): string
    {
        return trim(preg_replace('/\s+/', ' ', (new CarveConverter())->convert($source)) ?? '');
    }

    public function testAHeadingLeadEndsTheBody(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <ul> <li> <h1 id="h">h</h1> </li> </ul> </dd> </dl> <p>x</p>',
            $this->html(":: t\n: - # h\nx\n"),
        );
    }

    public function testACommentLeadEndsTheBody(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <ul> <li></li> </ul> </dd> </dl> <p>x</p>',
            $this->html(":: t\n: - %% c\nx\n"),
        );
    }

    public function testAnEntryBelowTheReleasedLineStillOpens(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <ul> <li> <h1 id="h">h</h1> </li> </ul> </dd> </dl> <p>x</p> <dl> <dt>u</dt> <dd>v</dd> </dl>',
            $this->html(":: t\n: - # h\nx\n\n:: u\n: v\n"),
        );
    }

    public function testAFenceLeadEndsTheBody(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <ul> <li> <pre><code></code></pre> </li> </ul> </dd> </dl> <p>x</p>',
            $this->html(":: t\n: - ```\n    ```\nx\n"),
        );
    }

    public function testATableLeadEndsTheBody(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <ul> <li> <table> <thead> <tr><th scope="col">a</th></tr> </thead> </table> </li> </ul> </dd> </dl> <p>x</p>',
            $this->html(":: t\n: - | a |\n    | - |\nx\n"),
        );
    }

    public function testARawBlockLeadEndsTheBody(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <ul> <li> </li> </ul> </dd> </dl> <p>x</p>',
            $this->html(":: t\n: - ```=html\n    ```\nx\n"),
        );
    }

    public function testACommentFenceAtTheBodyColumnStillEndsTheBody(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <ul> <li></li> </ul> </dd> </dl> <p>x</p>',
            $this->html(":: t\n: - %%%\n    c\n    %%%\nx\n"),
        );
    }

    public function testTheBodysOwnFenceEndsIt(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <pre><code></code></pre> </dd> </dl> <p>x</p>',
            $this->html(":: t\n: ```\n  ```\nx\n"),
        );
    }

    public function testTheBodysOwnHeadingEndsIt(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <h1 id="h">h</h1> </dd> </dl> <p>x</p>',
            $this->html(":: t\n: # h\nx\n"),
        );
    }

    public function testTheBodysOwnTableEndsIt(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <table> <thead> <tr><th scope="col">a</th></tr> </thead> </table> </dd> </dl> <p>x</p>',
            $this->html(":: t\n: | a |\n  | - |\nx\n"),
        );
    }

    public function testTheBodysOwnCommentEndsIt(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd></dd> </dl> <p>x</p>',
            $this->html(":: t\n: %% c\nx\n"),
        );
    }

    public function testAParagraphLeadStillTakesTheLineInTheItem(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <ul> <li>p x</li> </ul> </dd> </dl>',
            $this->html(":: t\n: - p\nx\n"),
        );
    }

    public function testTheListItemHostAlsoEndsOnAClosedFence(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> <pre><code></code></pre> </li> </ul> </li> </ul> <p>x</p>',
            $this->html("- - ```\n    ```\nx\n"),
        );
    }

    public function testTheListItemHostStillLeavesOnAHeading(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> <h1 id="h">h</h1> </li> </ul> </li> </ul> <p>x</p>',
            $this->html("- - # h\nx\n"),
        );
    }

    public function testTheListItemHostStillLeavesOnAComment(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li></li> </ul> </li> </ul> <p>x</p>',
            $this->html("- - %% c\nx\n"),
        );
    }

    public function testAnOpenNestedQuoteStillEndsTheBody(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <blockquote> <table> <tbody> <tr><td>a b</td></tr> </tbody> </table> </blockquote> </dd> </dl> <p>tail</p>',
            $this->html(":: t\n:  > | a |\n   > + b |\ntail\n"),
        );
    }

    public function testAnOpenNestedQuoteEndingOnAHeadingAlsoEndsTheBody(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <blockquote> <h1 id="h">h</h1> </blockquote> </dd> </dl> <p>tail</p>',
            $this->html(":: t\n:  > # h\ntail\n"),
        );
    }

    public function testAQuotesOpenParagraphStillTakesTheLine(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <blockquote><p>q tail</p></blockquote> </dd> </dl>',
            $this->html(":: t\n:  > q\ntail\n"),
        );
    }
}
