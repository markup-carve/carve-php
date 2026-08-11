<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A BLANK LINE INSIDE AN OPEN CONTAINER DOES NOT END THE LIST ITEM.
 *
 * `collectPlainListItemContinuation()` kept collecting across a blank line
 * while a CODE fence was open, and stopped while a `:::` DIV was open. So an
 * item holding an admonition with a blank line in it severed the div at that
 * blank, and the closer below it read as a fresh bare-div OPENER - the document
 * came back with a spurious empty `div` beside the aside. carve-js publishes
 * one aside for the same input. The exact sources are in the test bodies.
 *
 * The state the gate needed was already tracked: `advanceTrailingBlockState()`
 * maintains `inDiv` right beside `inFence`, and only the blank-line branch was
 * short of the case.
 *
 * This is what made the canonical writer glue an empty container's opener to
 * its closer (markup-carve/carve#961 ruling 1 moves it to a blank line), so the
 * writer's workaround and this defect are one change.
 */
class BlankLineInsideAnOpenDivInAnItemTest extends TestCase
{
    private function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testABlankLineInsideAnOpenAdmonitionDoesNotOpenASecondDiv(): void
    {
        $html = $this->html("- item\n  ::: note\n\n  :::\n\ntail\n");

        $this->assertStringNotContainsString('<div>', $html);
        $this->assertSame(1, substr_count($html, '<aside'));
    }

    public function testTheDivKeepsItsBodyAcrossTheBlankLine(): void
    {
        $html = $this->html("- item\n  ::: note\n  a\n\n  b\n  :::\n");

        $this->assertStringNotContainsString('<div>', $html);
        $this->assertStringContainsString('<p>a</p>', $html);
        $this->assertStringContainsString('<p>b</p>', $html);
    }

    /**
     * The blank line inside the container is the only thing that moves. An
     * item that ends WITH a blank line still ends there, because no container
     * is open across it.
     */
    public function testABlankAfterAClosedDivStillEndsTheItem(): void
    {
        $html = $this->html("- item\n  ::: note\n  a\n  :::\n\ntail\n");

        $this->assertStringContainsString('<p>tail</p>', $html);
        $this->assertStringNotContainsString('<li>tail', $html);
    }

    public function testABlankAfterAPlainItemStillEndsTheItem(): void
    {
        $html = $this->html("- item\n\ntail\n");

        $this->assertStringContainsString('<p>tail</p>', $html);
        $this->assertStringNotContainsString('<li>tail', $html);
    }

    /**
     * The blank line is a COLLECTED LINE and advances the trailing-block
     * tracker like any other. Left un-advanced, the tracker still reported the
     * open paragraph the line above the blank had left, and a flush-left line
     * below folded into a paragraph the blank had closed.
     */
    public function testAFlushLeftLineAfterABlankInsideTheDivIsATopLevelParagraph(): void
    {
        $html = $this->html("- item\n  ::: note\n  a\n\ntail\n");

        $this->assertStringContainsString("</aside>\n  </li>\n</ul>\n<p>tail</p>", $html);
    }

    /**
     * The code fence half was already right and must stay right: it is the
     * sibling this gate was measured against.
     */
    public function testABlankLineInsideAnOpenCodeFenceStillDoesNotEndTheItem(): void
    {
        $html = $this->html("- item\n  ```\n  a\n\n  b\n  ```\n");

        $this->assertStringContainsString("a\n\nb", $html);
    }
}
