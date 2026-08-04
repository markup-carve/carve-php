<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
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
        // Already correct in every engine; here so a fix that reached for
        // "always close" instead of "close when no paragraph is open" does not
        // pass by accident.
        $html = $this->squash($this->converter->convert("> ```\n> x\n> ```\nb\n"));

        $this->assertStringContainsString('</blockquote> <p>b</p>', $html);
    }
}
