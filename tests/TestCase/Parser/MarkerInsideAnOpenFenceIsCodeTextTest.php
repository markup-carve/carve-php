<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A LIST MARKER AT THE CONTENT COLUMN INSIDE AN OPEN FENCE IS CODE TEXT.
 *
 * §24 S1 matches the item, so the innermost MATCHED container is the FENCED
 * BODY, and S2 makes the line code text. Two collectors asked about the marker
 * BEFORE they asked about the fence, so a marker CHARACTER decided whether a
 * verbatim body was verbatim - while the plain-text sibling at the same column
 * has always been code (corpus
 * `276-a-fence-opened-on-a-list-marker-line-body-below-the-content-column-3`).
 *
 * Pinned upstream as corpus category 278,
 * `a-list-marker-at-the-content-column-inside-an-open-fence`
 * (markup-carve/carve#975); the two sources and their expected HTML are
 * reproduced here verbatim so this engine does not have to wait for a corpus
 * bump to hold the rule. carve-php-side issue markup-carve/carve-php#1007,
 * reference implementation markup-carve/carve-js#877.
 *
 * MEASURED PER LOOP rather than assumed: of the three collectors that ask this
 * question, one already consulted the fence state and did NOT move.
 */
class MarkerInsideAnOpenFenceIsCodeTextTest extends TestCase
{
    private function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    private function fence(): string
    {
        return str_repeat('`', 3);
    }

    /**
     * Corpus 278, row 1: the fence opens ON the marker line.
     */
    public function testAMarkerInAFenceOpenedOnTheMarkerLineIsCodeText(): void
    {
        $source = '- ' . $this->fence() . "\n  - x\n  " . $this->fence() . "\n";
        $expected = "<ul>\n  <li>\n    <pre><code>- x\n</code></pre>\n  </li>\n</ul>\n";

        $this->assertSame($expected, $this->html($source));
    }

    /**
     * Corpus 278, row 2: the fence opens after a blank line, below lead text.
     * This row already passed - the post-blank nested-content loop in
     * `tryParseList()` consults `inFence` already - and it is here so a fix to
     * the other two cannot quietly break it.
     */
    public function testAMarkerInAFenceOpenedAfterABlankIsCodeText(): void
    {
        $source = "- a\n\n  " . $this->fence() . "\n  - x\n  " . $this->fence() . "\n";
        $expected = "<ul>\n  <li>a\n    <pre><code>- x\n</code></pre>\n  </li>\n</ul>\n";

        $this->assertSame($expected, $this->html($source));
    }

    /**
     * The same shape with NO blank line above the fence, which the corpus does
     * not reach: it goes through the plain continuation collector rather than
     * the post-blank one.
     */
    public function testAMarkerInAFenceOpenedBelowLeadTextWithNoBlankIsCodeText(): void
    {
        $source = "- a\n  " . $this->fence() . "\n  - x\n  " . $this->fence() . "\n";

        $this->assertStringContainsString('<pre><code>- x', $this->html($source));
        $this->assertSame(1, substr_count($this->html($source), '<ul>'));
    }

    /**
     * The `+` continuation marker's attached block, which the corpus does not
     * reach either. carve-js has two loops here; carve-php has ONE collector
     * serving both the first-block form and the mid-item form, so both shapes
     * are pinned against the one fix.
     */
    public function testAMarkerInAFenceInTheFirstBlockAttachedToAContinuationMarkerIsCodeText(): void
    {
        $source = "- +\n" . $this->fence() . "\n- x\n" . $this->fence() . "\n";

        $this->assertStringContainsString('<pre><code>- x', $this->html($source));
        $this->assertSame(1, substr_count($this->html($source), '<li>'));
    }

    public function testAMarkerInAFenceAttachedMidItemIsCodeText(): void
    {
        $source = "- a\n\n+\n" . $this->fence() . "\n- x\n" . $this->fence() . "\n";

        $this->assertStringContainsString('<pre><code>- x', $this->html($source));
        $this->assertSame(1, substr_count($this->html($source), '<li>'));
    }

    /**
     * It is the FENCE that decides, not the marker character: every marker
     * shape inside one is code text, and a tilde fence is a fence.
     */
    public function testEveryMarkerShapeInsideAFenceIsCodeText(): void
    {
        foreach (['- x', '* x', '1. x', '1) x', 'i. x', 'a. x', '. x', '- [ ] x'] as $marker) {
            $source = '- ' . $this->fence() . "\n  " . $marker . "\n  " . $this->fence() . "\n";

            $this->assertStringContainsString(
                '<pre><code>' . $marker . "\n",
                $this->html($source),
                'marker: ' . $marker,
            );
        }
    }

    public function testATildeFenceDecidesTheSameWay(): void
    {
        $source = "- ~~~\n  - x\n  ~~~\n";

        $this->assertStringContainsString('<pre><code>- x', $this->html($source));
    }

    public function testTheRuleReachesANestedItem(): void
    {
        $source = '- - ' . $this->fence() . "\n    - x\n    " . $this->fence() . "\n";

        $this->assertStringContainsString('<pre><code>- x', $this->html($source));
    }

    /**
     * ONLY THE FENCE. A `:::` div's body is ordinary blocks, so a marker in one
     * IS a list and must still open one - suppressing the marker for every
     * open container would have made a div body verbatim too.
     */
    public function testAMarkerInsideADivIsStillAList(): void
    {
        $source = "- ::: note\n  - x\n  :::\n";

        $this->assertStringContainsString('<li>x</li>', $this->html($source));
    }

    /**
     * And a marker OUTSIDE any fence still ends the item, which is the
     * behavior the guard must not reach.
     */
    public function testAMarkerBelowAClosedFenceStillOpensASublist(): void
    {
        $source = '- ' . $this->fence() . "\n  code\n  " . $this->fence() . "\n  - x\n";

        $html = $this->html($source);
        $this->assertStringContainsString('<pre><code>code', $html);
        $this->assertStringContainsString('<li>x</li>', $html);
    }

    /**
     * The plain-text sibling the fixture family is named for keeps working.
     */
    public function testPlainTextAtTheContentColumnIsStillCodeText(): void
    {
        $source = '- ' . $this->fence() . "\n  x\n  " . $this->fence() . "\n";

        $this->assertStringContainsString('<pre><code>x', $this->html($source));
    }
}
