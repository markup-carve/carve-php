<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §24's STEP algorithm on a CODE fence whose body sits below the item's
 * content column (markup-carve/carve#950, corpus category
 * `276-a-fence-opened-on-a-list-marker-line-body-below-the-content-column`).
 *
 * The stack is walked by the indentation a line SUPPLIES, not by what the line
 * says. For `- ```` followed by a flush-left `x`:
 *
 * - S1 MATCH PREFIXES stops at the first container whose prefix the line does
 *   not supply. `x` supplies none, so the walk stops at the ITEM and the fenced
 *   body is never reached.
 * - S2 FENCED BODY therefore never fires: it applies only when the innermost
 *   MATCHED container is a fenced body.
 * - S4 PARTIAL MATCH governs, and its lazy branch continues an open PARAGRAPH.
 *   A verbatim body is not one, so there is nothing to fold into. What remains
 *   is S4's otherwise: close the unmatched containers and re-classify the
 *   residue in the surviving context.
 *
 * So the item holds an EMPTY code block and the residue re-parses at document
 * level. This engine used to keep collecting into the fence instead, on the
 * reasoning that an unterminated fence runs to end of input by §28 - which it
 * does, inside the container that opened it. The reach of a container is not
 * extended by what its innermost block happens to be, and the BLOCK QUOTE
 * spelling of the same shape already ended at that line here.
 *
 * The expectations are the corpus rows verbatim. Two of them are CONTROLS that
 * this engine already produced: the body AT the content column, and the block
 * quote analogue.
 */
class MarkerLineCodeFenceBelowColumnTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * Corpus row 1: the ticket's shape. The empty inline code in the residue is
     * a property of the backtick run, not of this rule - row 5 shows a tilde
     * fence leaving plain text there.
     */
    public function testAColumnZeroBodyClosesTheItem(): void
    {
        $this->assertSame(
            "<ul>\n  <li>\n    <pre><code>\n</code></pre>\n  </li>\n</ul>\n<p>x\n<code></code></p>\n",
            $this->converter->convert("- ```\nx\n```\n"),
        );
    }

    /**
     * Corpus row 2. A separate row because the broken readings differed here:
     * one kept the leading space in the code text and one stripped it. One
     * column is still below the content column, so the answer is row 1's.
     */
    public function testAColumnOneBodyClosesTheItemToo(): void
    {
        $this->assertSame(
            "<ul>\n  <li>\n    <pre><code>\n</code></pre>\n  </li>\n</ul>\n<p>x\n<code></code></p>\n",
            $this->converter->convert("- ```\n x\n ```\n"),
        );
    }

    /**
     * Corpus row 3, a CONTROL: at the content column the walk reaches the
     * fenced body, S2 fires, and the item keeps its code block. This is the
     * shape every pre-existing corpus case uses, and it did not change.
     */
    public function testABodyAtTheContentColumnStaysInTheFence(): void
    {
        $this->assertSame(
            "<ul>\n  <li>\n    <pre><code>x\n</code></pre>\n  </li>\n</ul>\n",
            $this->converter->convert("- ```\n  x\n  ```\n"),
        );
    }

    /**
     * Corpus row 4, a CONTROL: the block quote analogue. Unanimous across
     * engines and already correct here - the only difference from row 1 is
     * which container the walk stops at, which is what made the list spelling
     * a drift from a rule this engine already applied one container over.
     */
    public function testTheBlockQuoteAnalogueIsUnchanged(): void
    {
        $this->assertSame(
            "<blockquote>\n  <pre><code>\n</code></pre>\n</blockquote>\n<p>x\n<code></code></p>\n",
            $this->converter->convert("> ```\nx\n```\n"),
        );
    }

    /**
     * Corpus row 5: a tilde fence. The residue is plain text, which is what
     * shows the empty inline code in rows 1 and 2 belongs to the backtick run
     * rather than to this rule.
     */
    public function testATildeFenceLeavesPlainTextInTheResidue(): void
    {
        $this->assertSame(
            "<ul>\n  <li>\n    <pre><code>\n</code></pre>\n  </li>\n</ul>\n<p>x\n~~~</p>\n",
            $this->converter->convert("- ~~~\nx\n~~~\n"),
        );
    }

    /**
     * Corpus row 6, the row a marker-line-only fix fails. Once the body has
     * collected a line AT the content column, a reader tracking the item's
     * paragraph state sees a paragraph open again and folds the below-column
     * line in. The guard belongs on the OPEN FENCE, so the collected line
     * changes nothing.
     */
    public function testACollectedLineAtTheContentColumnDoesNotReopenTheFold(): void
    {
        $this->assertSame(
            "<ul>\n  <li>\n    <pre><code>x\n</code></pre>\n  </li>\n</ul>\n<p>y\n<code></code></p>\n",
            $this->converter->convert("- ```\n  x\n y\n  ```\n"),
        );
    }

    /**
     * Corpus row 7: a fence opened on a CONTINUATION line rather than on the
     * marker line. The same clause decides it, and what the truncated item then
     * holds is §10 I4's business - the fence left inside has no closer, so it
     * does not interrupt the item's open paragraph and stays inline content.
     */
    public function testAFenceOpenedOnAContinuationLineEndsAtTheSameLine(): void
    {
        $this->assertSame(
            "<ul>\n  <li>a\n<code>\nb</code></li>\n</ul>\n<p>y\n<code></code></p>\n",
            $this->converter->convert("- a\n  ```\n  b\n y\n  ```\n"),
        );
    }

    /**
     * The POST-BLANK nested-content collector is the second producer of this
     * answer, and it had the same fence exemption. A fence opened in an item's
     * nested stream does not extend the item's reach either.
     */
    public function testAPostBlankFenceEndsAtAColumnZeroLine(): void
    {
        $this->assertSame(
            "<ul>\n  <li>a\n    <pre><code>\n</code></pre>\n  </li>\n</ul>\n<p>x\n<code></code></p>\n",
            $this->converter->convert("- a\n\n  ```\nx\n```\n"),
        );
    }

    /**
     * The same collector's intermediate-indent branch: between the base column
     * and the content column the line still supplies less than the item's
     * prefix, so the walk stops at the item exactly as it does at column 0.
     */
    public function testAPostBlankFenceEndsAtAnIntermediateColumnLine(): void
    {
        $this->assertSame(
            "<ul>\n  <li>a\n    <pre><code>\n</code></pre>\n  </li>\n</ul>\n<p>x\n<code></code></p>\n",
            $this->converter->convert("- a\n\n  ```\n x\n ```\n"),
        );
    }

    /**
     * CONTROL for the two above: with the fence CLOSED there is no open
     * paragraph either, and the below-column line already ended the item. The
     * fence exemption was the only thing making the open case differ.
     */
    public function testAClosedPostBlankFenceAlreadyEndedTheItem(): void
    {
        $this->assertSame(
            "<ul>\n  <li>a\n    <pre><code>b\n</code></pre>\n  </li>\n</ul>\n<p>x</p>\n",
            $this->converter->convert("- a\n\n  ```\n  b\n  ```\nx\n"),
        );
    }
}
