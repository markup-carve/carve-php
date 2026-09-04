<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 1 S4 makes lazy continuation conditional on an OPEN PARAGRAPH:
 *
 *   "if ANY container in the open stack holds an OPEN PARAGRAPH and the residue
 *   is NOT an interrupting line, L folds into the INNERMOST such paragraph and
 *   NOTHING closes. Otherwise close the unmatched containers and re-classify
 *   the residue in the surviving context."
 *
 * This engine kept the quote open after three constructs that leave no
 * paragraph behind: a heading, a definition term and a footnote definition. The
 * assertions are against S4 rather than against another engine, which is what
 * carve-php#652 settled when the majority read it the other way.
 *
 * REMEASURED (carve-php#1863). The cross-engine split that sentence described -
 * carve-rs applying the condition in every case and carve-js in two of the three
 * (carve-js#554) - no longer holds: all three engines and the executable spec
 * now render all three of these documents identically. The claim was true when
 * it was written and had aged into a false statement about the current engines,
 * so it is recorded as history rather than as a live comparison.
 *
 * Every cross-engine claim in this class was measured against carve-js at
 * 3eb0277, carve-rs at e6cac0d, and the executable spec at the revision
 * `tests/spec` is pinned to. A claim without a revision beside it is a claim
 * nobody can re-check.
 */
class LazyContinuationNeedsAnOpenParagraphTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    private function squash(string $html): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $html));
    }

    public function testAnOpenParagraphStillFolds(): void
    {
        // The control. Pinned by corpus 82-blockquote-lazy-continuation.
        $this->assertSame(
            '<blockquote><p>a b</p></blockquote>',
            $this->squash($this->converter->convert("> a\nb\n")),
        );
    }

    public function testAHeadingLeavesNoParagraphToFoldInto(): void
    {
        // PART 9 §10 I6 names this one twice over: "HEADING is the SOLE
        // exception: a bounded title holds no block and ENDS AT THE NEWLINE,
        // so nothing folds into it at all."
        $this->assertSame(
            '<blockquote> <h1 id="h">h</h1> </blockquote> <p>b</p>',
            $this->squash($this->converter->convert("> # h\nb\n")),
        );
    }

    public function testADefinitionTermLeavesNoParagraphToFoldInto(): void
    {
        // A term is a bounded single line like a heading - it holds inline
        // content, not a paragraph - so a lazy line cannot extend it.
        //
        // The space after `>` is REQUIRED (carve#525). This case was written
        // as `>:: t` while that was still a quote, and the rule landed one
        // minute before this file did - so main went red on a test whose own
        // PR had been green against the older base.
        $this->assertSame(
            '<blockquote> <dl> <dt>t</dt> </dl> </blockquote> <p>~</p>',
            $this->squash($this->converter->convert("> :: t\n~\n")),
        );
    }

    public function testAFootnoteDefinitionLeavesNoParagraphToFoldInto(): void
    {
        // An invisible construct leaves no paragraph at all.
        $this->assertSame(
            '<blockquote> </blockquote> <p>/</p>',
            $this->squash($this->converter->convert("> [f]: ~\n/\n")),
        );
    }

    public function testAMarkerLineSubListStillHoldsAnOpenParagraph(): void
    {
        // Where the sub-list opens does not enter into S4: the sub-item's
        // paragraph is open either way, so the flush-left line folds into it.
        // The same two lines written as `- x` / `  - a` / `b` already folded
        // here; the marker-line branch collected its item without ever reaching
        // the lazy rule (carve-php#693).
        $this->assertSame(
            '<ul> <li> <ul> <li>a b</li> </ul> </li> </ul>',
            $this->squash($this->converter->convert("- - a\nb\n")),
        );
    }

    public function testABelowColumnBlockOpenerFoldsAsTextIntoTheSubItem(): void
    {
        // One column in, `# H` is below the sub-list's content column, so it is
        // paragraph text rather than a heading - and it folds like any other
        // lazy line. The lazy line therefore keeps its own indentation into the
        // item's stream; dedenting it would have promoted it to a heading.
        $this->assertSame(
            '<ul> <li> <ul> <li>a # H</li> </ul> </li> </ul>',
            $this->squash($this->converter->convert("- - a\n # H\n")),
        );
    }

    public function testAFlushLeftBlockOpenerStillEndsTheItem(): void
    {
        // At column 0 the heading is a heading, so it interrupts as always.
        $this->assertSame(
            '<ul> <li> <ul> <li>a</li> </ul> </li> </ul> <section id="H"> <h1>H</h1> </section>',
            $this->squash($this->converter->convert("- - a\n# H\n")),
        );
    }

    public function testABlankClosesTheSubItemsParagraph(): void
    {
        // With the blank there is no open paragraph left, so the list ends and
        // the line is a document paragraph (carve-php#681 pinned the loosening
        // half of this shape).
        $this->assertSame(
            '<ul> <li> <ul> <li>a</li> </ul> </li> </ul> <p>b</p>',
            $this->squash($this->converter->convert("- - a\n\nb\n")),
        );
    }

    public function testALazyLineReachesTheDeepestOpenParagraph(): void
    {
        // S4 folds into the INNERMOST open paragraph, which here is inside the
        // sub-item's block quote, not the sub-item itself.
        $this->assertSame(
            '<ul> <li> <ul> <li> <blockquote><p>q b</p></blockquote> </li> </ul> </li> </ul>',
            $this->squash($this->converter->convert("- - > q\nb\n")),
        );
    }

    public function testAClosedFenceInTheStreamLeavesNothingToFoldInto(): void
    {
        // The item's stream ends with a CLOSED fence, so the dedented line ends
        // the item instead of being absorbed - the same rule as the quote case
        // below, applied to the marker-line branch.
        $this->assertSame(
            '<ul> <li> <ul> <li>a</li> </ul> <pre><code>code </code></pre> </li> </ul> <p>c</p>',
            $this->squash($this->converter->convert("- - a\n  ```\n  code\n  ```\nc\n")),
        );
    }

    public function testAClosedFenceAlreadyClosedTheQuote(): void
    {
        // Already correct in every engine - CONFIRMED, all three engines and
        // the executable spec agree byte for byte. Here so a fix that reached
        // for "always close" instead of "close when no paragraph is open" does
        // not pass by accident.
        $html = $this->squash($this->converter->convert("> ```\n> x\n> ```\nb\n"));

        $this->assertStringContainsString('</blockquote> <p>b</p>', $html);
    }

    /**
     * S4 BINDS AT EVERY DEPTH, and the clause says so: it holds "even where the
     * unmatched container is a LIST ITEM whose last block is a container"
     * (markup-carve/carve#1280).
     *
     * This engine applied it at depth 1 and folded at depth 2, so it answered
     * the same question two ways depending on nesting - and the clause makes no
     * reference to depth. The fold was not even the shape a lazy continuation
     * produces: `lazy` landed in the OUTER item as bare text with no paragraph
     * wrapper.
     *
     * One row per last-block kind rather than one for the family, because the
     * tracker decides each kind in its own branch and a fix that reached only
     * the heading would leave the rest folding
     * (markup-carve/carve-php#1403, markup-carve/carve-php#1404).
     *
     * @return array<string, array{string, string}>
     */
    public static function everyDepthClosesProvider(): array
    {
        return [
            'heading' => [
                "- - # H\nlazy\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <h1 id=\"H\">H</h1>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>lazy</p>\n",
            ],
            'comment' => [
                "- - %% c\nlazy\n",
                "<ul>\n  <li>\n    <ul>\n      <li></li>\n    </ul>\n  </li>\n</ul>\n<p>lazy</p>\n",
            ],
            'table' => [
                "- - | a |\nlazy\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <table>\n          <tbody>\n            <tr><td>a</td></tr>\n          </tbody>\n        </table>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>lazy</p>\n",
            ],
            'thematic break' => [
                "- - ---\nlazy\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <hr>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>lazy</p>\n",
            ],
            'ordered markers' => [
                "1. 1. # H\nlazy\n",
                "<ol>\n  <li>\n    <ol>\n      <li>\n        <h1 id=\"H\">H</h1>\n      </li>\n    </ol>\n  </li>\n</ol>\n<p>lazy</p>\n",
            ],
            // A marker-only quote holds nothing, which is the blank-line branch
            // one recursion in - the same way the heading row is the heading
            // branch one recursion in.
            'empty quote' => [
                "- - >\nlazy\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <blockquote>\n\n        </blockquote>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>lazy</p>\n",
            ],
            // Depth 3 folded exactly ONE level in rather than not at all, which
            // is where the missing level of the walk showed.
            'depth three' => [
                "- - - # H\nlazy\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <ul>\n          <li>\n            <h1 id=\"H\">H</h1>\n          </li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>lazy</p>\n",
            ],
            // The nested marker is not the outer item's LEAD here, so the walk
            // reaches it with a paragraph already open and behind it. The rule
            // is about the container's LAST block, not its first.
            'nested list after a paragraph' => [
                "- a\n  - # H\nlazy\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>\n        <h1 id=\"H\">H</h1>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>lazy</p>\n",
            ],
        ];
    }

    #[DataProvider('everyDepthClosesProvider')]
    public function testEveryDepthCloses(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($input));
    }

    /**
     * Where a paragraph IS open the line folds, at every depth.
     *
     * This is the shape a careless fix breaks - "close when the lead is a
     * marker" passes every row above and loses all of these.
     *
     * @return array<string, array{string, string}>
     */
    public static function anOpenParagraphStillFoldsAtEveryDepthProvider(): array
    {
        return [
            'depth one' => [
                "- a\nlazy\n",
                "<ul>\n  <li>a\nlazy</li>\n</ul>\n",
            ],
            'depth two' => [
                "- - a\nlazy\n",
                "<ul>\n  <li>\n    <ul>\n      <li>a\nlazy</li>\n    </ul>\n  </li>\n</ul>\n",
            ],
            'depth three' => [
                "- - - a\nlazy\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <ul>\n          <li>a\nlazy</li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>\n",
            ],
        ];
    }

    #[DataProvider('anOpenParagraphStillFoldsAtEveryDepthProvider')]
    public function testAnOpenParagraphStillFoldsAtEveryDepth(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($input));
    }

    public function testDepthOneWasAlreadyRightAndStaysRight(): void
    {
        // The row the engine already passed. It is here because the walk that
        // answers depth 2 runs at depth 1 too, and the marker line never
        // reaches it there - the item's lead arrives with the marker already
        // off. A change that moved this would mean the walk had started
        // answering a question it is not asked.
        $this->assertSame(
            "<ul>\n  <li>\n    <h1 id=\"H\">H</h1>\n  </li>\n</ul>\n<p>lazy</p>\n",
            $this->converter->convert("- # H\nlazy\n"),
        );
    }

    /**
     * AN UNFINISHED `:::` OPENER ON THE NESTED LEAD IS STILL PROSE - AND A
     * FENCE IS NOT.
     *
     * A `:::` opener continues onto lines the nested walk never sees, so the
     * walk has not read the block it would report on. Reporting anyway changed
     * what the item CONTAINS and not just where the lazy line went: the div row
     * below turned a literal `::: note` into a real admonition and moved `b`
     * out of the item. A CODE FENCE is the opposite case and is ruled the other
     * way - see the `code fence` note below.
     *
     * WHAT THE ROWS ACTUALLY AGREE WITH (measured for carve-php#1863; the
     * previous claim here was "Both rows match carve-js and carve-rs", and
     * neither row does):
     *
     *   - `div opener` matches carve-js AND the executable spec. carve-rs is
     *     the outlier - it reads the opener as a real admonition and publishes
     *     `b` at document level. Same family as markup-carve/carve-rs#1510.
     *   - `code fence` was pinned here as the engine's own answer awaiting a
     *     ruling, and markup-carve/carve#1900 has since given one: the fence
     *     OWNS the flush-left lines below it and the closing run is body text,
     *     because a fence's content is not re-scanned for structure. The row
     *     now holds the executable spec's bytes, which is also what the
     *     abandoned shape never was - the `pre` is no longer EMPTY, the body no
     *     longer leaks into the outer item, and the closer is no longer a stray
     *     inline `code`. See markup-carve/carve-php#1900 and
     *     UnfinishedFenceOnANestedLeadOwnsItsBodyTest, which covers the family.
     *
     * @return array<string, array{string, string}>
     */
    public static function anUnfinishedOpenerStaysProseProvider(): array
    {
        return [
            'code fence' => [
                "- - ``` x\ncode\n```\n- lazy\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\">code\n```\n</code></pre>\n      </li>\n    </ul>\n  </li>\n  <li>lazy</li>\n</ul>\n",
            ],
            'div opener' => [
                "- - ::: note\nb\n:::\nlazy\n",
                "<ul>\n  <li>\n    <ul>\n      <li>::: note\nb</li>\n    </ul>\n  </li>\n</ul>\n<div>\n  <p>lazy</p>\n</div>\n",
            ],
        ];
    }

    #[DataProvider('anUnfinishedOpenerStaysProseProvider')]
    public function testAnUnfinishedOpenerStaysProse(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($input));
    }
}
