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
 * paragraph behind: a heading, a definition term and a footnote definition.
 * carve-rs applies the condition in every case; carve-js shares two of the
 * three (carve-js#554). The majority was wrong here, which is why these assert
 * against S4 rather than against the other engines (carve-php#652).
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
            $this->squash($this->converter->convert("> a\n> b\n")),
        );
    }

    public function testAHeadingLeavesNoParagraphToFoldInto(): void
    {
        // PART 9 §10 I6 names this one twice over: "HEADING is the SOLE
        // exception: a bounded title holds no block and ENDS AT THE NEWLINE,
        // so nothing folds into it at all."
        $this->assertSame(
            '<blockquote> <h1 id="h">h</h1> </blockquote> <p>b</p>',
            $this->squash($this->converter->convert("> # h\n\nb\n")),
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
            $this->squash($this->converter->convert("> :: t\n\n~\n")),
        );
    }

    public function testAFootnoteDefinitionLeavesNoParagraphToFoldInto(): void
    {
        // An invisible construct leaves no paragraph at all.
        $this->assertSame(
            '<blockquote> </blockquote> <p>/</p>',
            $this->squash($this->converter->convert(">\n\n/\n\n[f]: ~\n")),
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
            $this->squash($this->converter->convert("- - a\n    b\n")),
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
            $this->squash($this->converter->convert("- - a\n    # H\n")),
        );
    }

    public function testAFlushLeftBlockOpenerStillEndsTheItem(): void
    {
        // At column 0 the heading is a heading, so it interrupts as always.
        $this->assertSame(
            '<ul> <li> <ul> <li>a</li> </ul> </li> </ul> <section id="H"> <h1>H</h1> </section>',
            $this->squash($this->converter->convert("- - a\n\n# H\n")),
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
            $this->squash($this->converter->convert("- - > q\n    > b\n")),
        );
    }

    public function testAClosedFenceInTheStreamLeavesNothingToFoldInto(): void
    {
        // The item's stream ends with a CLOSED fence, so the dedented line ends
        // the item instead of being absorbed - the same rule as the quote case
        // below, applied to the marker-line branch.
        $this->assertSame(
            '<ul> <li> <ul> <li>a</li> </ul> <pre><code>code </code></pre> </li> </ul> <p>c</p>',
            $this->squash($this->converter->convert("- - a\n\n  ```\n  code\n  ```\n\nc\n")),
        );
    }

    public function testAClosedFenceAlreadyClosedTheQuote(): void
    {
        // Already correct in every engine; here so a fix that reached for
        // "always close" instead of "close when no paragraph is open" does not
        // pass by accident.
        $html = $this->squash($this->converter->convert("> ```\n> x\n> ```\n\nb\n"));

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
     * AN UNFINISHED OPENER ON THE NESTED LEAD IS STILL PROSE.
     *
     * A code fence or a `:::` opener continues onto lines the nested walk never
     * sees, so it has not read the block it would report on. Reporting anyway
     * changed what the item CONTAINS and not just where the lazy line went: the
     * div row below turned a literal `::: note` into a real admonition and moved
     * `b` out of the item. Both rows match carve-js and carve-rs, and both
     * matched before this rule reached depth 2 - they are the half a wider fix
     * takes away.
     *
     * @return array<string, array{string, string}>
     */
    public static function anUnfinishedOpenerStaysProseProvider(): array
    {
        return [
            'code fence' => [
                "- - ``` x\ncode\n```\n- lazy\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code class=\"language-x\">\n</code></pre>\n      </li>\n    </ul>\n    code\n<code></code>\n  </li>\n  <li>lazy</li>\n</ul>\n",
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
