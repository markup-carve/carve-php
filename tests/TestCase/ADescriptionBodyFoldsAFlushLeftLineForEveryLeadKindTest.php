<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A description body keeps a flush-left line whatever its nested item ended on.
 *
 * PART 9 §24 C3 asks the innermost container the line reaches, and a nested list
 * whose item is still open IS that container, so the body folds the line for a
 * fence, a table, a heading and a comment alike (markup-carve/carve-php#2904).
 *
 * THIS HOST DOES NOT FOLLOW carve#2734's LIST-ITEM ARMS. There, a closed fence
 * or raw block folds and a heading or comment leaves. A reader will reasonably
 * expect the two hosts to agree; the spec renders them differently and both are
 * reproduced as measured, which is why the list-item controls are carried here.
 *
 * Expectations are measured against the executable spec at the revision
 * `tests/spec` is pinned to (`scripts/spec/layout.mjs` into
 * `scripts/spec/html.mjs`), never read back from this engine.
 */
class ADescriptionBodyFoldsAFlushLeftLineForEveryLeadKindTest extends TestCase
{
    private function html(string $source): string
    {
        return trim(preg_replace('/\s+/', ' ', (new CarveConverter())->convert($source)) ?? '');
    }

    public function testAHeadingLeadStillFoldsTheLineIntoTheBody(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <ul> <li> <h1 id="h">h</h1> </li> </ul> <p>x</p> </dd> </dl>',
            $this->html(":: t\n: - # h\nx\n"),
        );
    }

    public function testACommentLeadStillFoldsTheLineIntoTheBody(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <ul> <li></li> </ul> <p>x</p> </dd> </dl>',
            $this->html(":: t\n: - %% c\nx\n"),
        );
    }

    /**
     * An entry below the folded line still opens, so the fold does not swallow
     * the rest of the list.
     */
    public function testAnEntryBelowTheFoldedLineStillOpens(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <ul> <li> <h1 id="h">h</h1> </li> </ul> <p>x</p> </dd> <dt>u</dt> <dd>v</dd> </dl>',
            $this->html(":: t\n: - # h\nx\n\n:: u\n: v\n"),
        );
    }

    /**
     * The two kinds that already folded, pinned so this change is visibly an
     * extension of one rule rather than a second one.
     */
    public function testAFenceLeadKeepsFolding(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <ul> <li> <pre><code></code></pre> </li> </ul> <p>x</p> </dd> </dl>',
            $this->html(":: t\n: - ```\n    ```\nx\n"),
        );
    }

    public function testATableLeadKeepsFolding(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <ul> <li> <table> <thead> <tr><th scope="col">a</th></tr> </thead> </table> </li> </ul> <p>x</p> </dd> </dl>',
            $this->html(":: t\n: - | a |\n    | - |\nx\n"),
        );
    }

    public function testARawBlockLeadKeepsFolding(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <ul> <li> </li> </ul> <p>x</p> </dd> </dl>',
            $this->html(":: t\n: - ```=html\n    ```\nx\n"),
        );
    }

    /**
     * A `%%%` fence written at the BODY'S content column is the body's own
     * finished block rather than the nested item's, and the spec ends the body
     * on it. The boundary of the rule, and not a kind that folds.
     */
    public function testACommentFenceAtTheBodyColumnStillEndsTheBody(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <ul> <li></li> </ul> </dd> </dl> <p>x</p>',
            $this->html(":: t\n: - %%%\n    c\n    %%%\nx\n"),
        );
    }

    /**
     * The body's OWN last block closes it in every kind, which is the control
     * that keeps the fold tied to a still-open nested container.
     */
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

    /**
     * §10 lazy continuation is untouched: a paragraph lead takes the line in
     * the nested ITEM, not as a block of the body.
     */
    public function testAParagraphLeadStillTakesTheLineInTheItem(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <ul> <li>p x</li> </ul> </dd> </dl>',
            $this->html(":: t\n: - p\nx\n"),
        );
    }

    /**
     * The LIST-ITEM host must keep carve#2734's arms, which split on the block
     * kind where this host does not. These are what stop the fix widening
     * across hosts.
     */
    public function testTheListItemHostStillFoldsOnAClosedFence(): void
    {
        $this->assertSame(
            '<ul> <li> <ul> <li> <pre><code></code></pre> </li> </ul> x </li> </ul>',
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

    /**
     * A nested QUOTE is not a nested list. The spec ends the body on one in the
     * same position, for a table and for a heading alike, so the fold reads the
     * container's KIND and not just that a container is open. Carried here
     * because an earlier version of this fix keyed on the open container alone
     * and moved these two.
     */
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

    /**
     * And a quote's OWN open paragraph is still §10's, inside the quote.
     */
    public function testAQuotesOpenParagraphStillTakesTheLine(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd> <blockquote><p>q tail</p></blockquote> </dd> </dl>',
            $this->html(":: t\n:  > q\ntail\n"),
        );
    }
}
