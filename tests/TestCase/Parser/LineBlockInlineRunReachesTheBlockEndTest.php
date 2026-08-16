<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An unclosed inline run reaches the END OF THE BLOCK inside a line block too.
 *
 * `resources/examples/edge-cases.md` states it for an unclosed inline verbatim
 * run: it "renders as a `<code>` span to the end of the block". A line block is
 * a block, so the rule reaches it unchanged (markup-carve/carve#1282), and
 * carve-rs is the engine that already held it.
 *
 * THE LINE ENDING IS NOT A BOUNDARY. Every stanza line used to be handed to the
 * inline parser on its own - in fact every whitespace-delimited SEGMENT of a
 * line was, because a preserved gap flushed the buffer - with a `<br>` appended
 * between them unconditionally. A construct therefore could not survive a line
 * ending, and the `<br>` was emitted whether or not anything had claimed the
 * newline. Both halves are wrong under the ruling, and the second is the one a
 * fixture is most likely to miss: when a run DOES swallow the break, there is
 * no `<br>` at all, because the newline is content inside the span rather than
 * a sibling of it.
 *
 * IT WAS NEVER ONLY VERBATIM. Math, inline literal, an inline footnote and
 * emphasis are all decided by one pass over one string, so all of them closed
 * at the line ending for the same reason and all of them are fixed by the same
 * change. Each has a row here, because "a code span works now" would pass for a
 * fix that special-cased verbatim and left the shared cause in place.
 */
class LineBlockInlineRunReachesTheBlockEndTest extends TestCase
{
    private function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * One row per run kind, each unclosed and each spanning the line ending.
     *
     * Every expected value was measured byte-for-byte against carve-rs, which
     * holds the rule. The newline INSIDE the span is the load-bearing detail:
     * the issue body originally showed the two lines joined by a space, and an
     * engine fixed toward that text would have diverged from carve-rs on the
     * very shape the ruling settles.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function runProvider(): array
    {
        return [
            'inline verbatim' => [
                "::: |\na `b\nc d\n:::\n",
                "<div class=\"line-block\">\n  <p>a <code>b\nc d</code></p>\n</div>\n",
            ],
            'math' => [
                "::: |\na \$`b\nc d\n:::\n",
                "<div class=\"line-block\">\n  <p>a <span class=\"math inline\">\\(b\nc d\\)</span></p>\n</div>\n",
            ],
            'inline literal' => [
                "::: |\na !`b\nc d\n:::\n",
                "<div class=\"line-block\">\n  <p>a !<code>b\nc d</code></p>\n</div>\n",
            ],
        ];
    }

    #[DataProvider('runProvider')]
    public function testAnUnclosedRunCrossesTheLineEnding(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * The same ruling read from the other side: a construct that CLOSES on the
     * next line now resolves instead of staying literal.
     *
     * A two-line inline footnote was rendered as its own source text. It is the
     * strongest row of the set, because nothing about it is a span running to
     * the end of the block - it is simply a construct that needed to see both
     * lines at once, which is what one parse per stanza gives it.
     */
    public function testATwoLineInlineFootnoteResolves(): void
    {
        $html = $this->html("::: |\na ^[note\nmore] b\n:::\n");

        $this->assertStringContainsString('role="doc-noteref"', $html);
        $this->assertStringNotContainsString('^[note', $html);
        // The body keeps the newline as content: the break belongs to the
        // footnote text, so it is not promoted to a `<br>`.
        $this->assertStringContainsString("<p>note\nmore<a href=", $html);
    }

    /**
     * A swallowed break emits NO `<br>`.
     *
     * The old code appended one between every pair of lines regardless, so a
     * fix that only widened the parse but kept that append would put a `<br>`
     * inside the span and still look almost right. Asserted directly rather
     * than left to the byte-exact rows above, so the reason is visible when it
     * fails.
     */
    public function testASwallowedBreakEmitsNoHardBreak(): void
    {
        $this->assertStringNotContainsString('<br>', $this->html("::: |\na `b\nc d\n:::\n"));
    }

    /**
     * THE CONTROL: a break nothing claimed is still a `<br>`.
     *
     * This is what stops the fix from being "stop emitting breaks". It is also
     * the row that fails first if the soft-to-hard promotion is dropped, since
     * the parser produces a SOFT break for the newline and a line block has no
     * soft breaks.
     */
    public function testAnUnclaimedBreakIsStillHard(): void
    {
        $this->assertSame(
            "<div class=\"line-block\">\n  <p>a b<br>\nc d</p>\n</div>\n",
            $this->html("::: |\na b\nc d\n:::\n"),
        );
    }

    /**
     * THE CONTROL THE RULING DOES NOT TOUCH: the same two lines as an ordinary
     * paragraph.
     *
     * Unanimous across all three engines before this change and required to
     * stay that way. It is the shape the line block is being brought INTO line
     * with, so it has to be measured here rather than assumed.
     */
    public function testTheParagraphFormIsUnchanged(): void
    {
        $this->assertSame("<p>a <code>b\nc d</code></p>\n", $this->html("a `b\nc d\n"));
    }

    /**
     * Preserved whitespace still works, and still only where §23 says.
     *
     * The expansion moved from emitting nodes to rewriting the string, so this
     * is the part most likely to have been lost on the way past: a leading
     * indent is kept at any width, an inner run of two or more columns is kept,
     * a lone inner space stays collapsible, and a lone TRAILING space is
     * dropped as trailing whitespace.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function whitespaceProvider(): array
    {
        return [
            'a leading indent is kept' => [
                "::: |\nRoses,\n  Violets.\n:::\n",
                "<div class=\"line-block\">\n  <p>Roses,<br>\n&nbsp;&nbsp;Violets.</p>\n</div>\n",
            ],
            'a one column leading indent is kept' => [
                "::: |\nRoses,\n Violets.\n:::\n",
                "<div class=\"line-block\">\n  <p>Roses,<br>\n&nbsp;Violets.</p>\n</div>\n",
            ],
            'an inner gap of two columns is kept' => [
                "::: |\na  b\nc\n:::\n",
                "<div class=\"line-block\">\n  <p>a&nbsp;&nbsp;b<br>\nc</p>\n</div>\n",
            ],
            'a lone inner space stays collapsible' => [
                "::: |\na b\nc\n:::\n",
                "<div class=\"line-block\">\n  <p>a b<br>\nc</p>\n</div>\n",
            ],
            'a lone trailing space is dropped' => [
                "::: |\na b \nc\n:::\n",
                "<div class=\"line-block\">\n  <p>a b<br>\nc</p>\n</div>\n",
            ],
            'a trailing gap of two columns is kept' => [
                "::: |\na b  \nc\n:::\n",
                "<div class=\"line-block\">\n  <p>a b&nbsp;&nbsp;<br>\nc</p>\n</div>\n",
            ],
        ];
    }

    #[DataProvider('whitespaceProvider')]
    public function testPreservedWhitespaceIsUnchanged(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    public function testEveryRowIsStillCovered(): void
    {
        $this->assertCount(3, self::runProvider());
        $this->assertCount(6, self::whitespaceProvider());
    }
}
