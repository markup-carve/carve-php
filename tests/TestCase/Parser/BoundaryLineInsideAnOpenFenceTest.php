<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A boundary line inside an open fence does not end the container
 * (markup-carve/carve#983 corpus category 279, markup-carve/carve-php#1049).
 *
 * PART 9 §17 L3 names the block kinds a `+` continuation marker may attach -
 * "ONE block of ANY kind (paragraph, list, fenced code, table, block quote,
 * div, ...)" - and bounds the attachment "up to the next blank line, sibling
 * marker, or a further `+`". Those bound THE BLOCK. A fenced block ends at its
 * CLOSER, which is what makes it one block, so a boundary line written between
 * an opener and its closer is fence content and ends nothing. Reading the blank
 * as reaching INSIDE the fence makes "fenced code" unattachable the moment its
 * body holds one, which is the kind L3 goes out of its way to name.
 *
 * SIX COLLECTORS ASKED THE SAME QUESTION AND SIX ANSWERED IT WRONG. The `+`
 * marker is collected in six separate loops in this parser - a footnote body
 * twice (the PASS 1 collector and the PASS 2 skip that mirrors it), a `dd`
 * twice (the first-block form and the mid-body form), a block quote, and a list
 * item once for both its `+` paths - and only one consulted a fence at all, and
 * only the code fence. The opener came out an empty block, the tail escaped to
 * document level,
 * and a code fence's closer came back as an empty inline code span.
 *
 * ONE SPELLING FOR EVERY CONTAINER. `collectAttachedBlock()` takes the boundary
 * set as its `$isBoundary` closure, which is the only per-container part; the
 * fence rule is `attachedFencedBlockEnd()`'s and is shared. A mutation
 * reverting ONE caller fails only that caller's rows; a mutation removing ONE
 * fence kind from the shared helper fails that kind across EVERY caller. That
 * pair of opposite results is what "one spelling" means here, and it is why the
 * rows below are written as a cross product rather than one example per bug.
 */
class BoundaryLineInsideAnOpenFenceTest extends TestCase
{
    /**
     * A code fence whose body holds a blank line: `a` and `b` are one block.
     *
     * @var string
     */
    private const CODE = "```\na\n\nb\n```";

    /**
     * A colon fence whose body holds a blank line: `a` and `b` are two
     * paragraphs of ONE admonition.
     *
     * @var string
     */
    private const COLON = "::: note\na\n\nb\n:::";

    /**
     * A comment fence whose body holds a blank line. It renders nothing at all.
     *
     * @var string
     */
    private const COMMENT = "%%%\na\n\nb\n%%%";

    /**
     * @var string
     */
    private const CODE_HTML = '<pre><code>a b </code></pre>';

    /**
     * @var string
     */
    private const COLON_HTML = '<aside class="admonition note"><p>a</p><p>b</p></aside>';

    protected function html(string $source): string
    {
        $html = (new CarveConverter())->convert($source);
        $html = (string)preg_replace('/\s+/', ' ', $html);

        return trim((string)str_replace('> <', '><', $html));
    }

    /**
     * The footnote body's rendered wrapper, so a row asserts on the BODY.
     *
     * @param string $body
     *
     * @return string
     */
    private function note(string $body): string
    {
        return '<p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>'
            . '<section role="doc-endnotes"><hr><ol><li id="fn1"><p>n</p>'
            . $body
            . '<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>';
    }

    // ---------------------------------------------------------------- item `+`

    public function testTheListItemPlusCollectorKeepsACodeFenceWhole(): void
    {
        $this->assertSame(
            '<ul><li>x ' . self::CODE_HTML . '</li></ul><p>z</p>',
            $this->html("- x\n+\n" . self::CODE . "\n\nz\n"),
        );
    }

    public function testTheListItemPlusCollectorKeepsAColonFenceWhole(): void
    {
        $this->assertSame(
            '<ul><li>x ' . self::COLON_HTML . '</li></ul><p>z</p>',
            $this->html("- x\n+\n" . self::COLON . "\n\nz\n"),
        );
    }

    public function testTheListItemPlusCollectorKeepsACommentFenceWhole(): void
    {
        // The whole span is invisible, so the item holds only its lead text and
        // nothing escapes to document level.
        $this->assertSame(
            '<ul><li>x</li></ul><p>z</p>',
            $this->html("- x\n+\n" . self::COMMENT . "\n\nz\n"),
        );
    }

    // --------------------------------------------------------- first-block `- +`

    public function testTheFirstBlockCollectorKeepsACodeFenceWhole(): void
    {
        $this->assertSame(
            '<ul><li>' . self::CODE_HTML . '</li></ul><p>z</p>',
            $this->html("- +\n" . self::CODE . "\n\nz\n"),
        );
    }

    public function testTheFirstBlockCollectorKeepsAColonFenceWhole(): void
    {
        $this->assertSame(
            '<ul><li>' . self::COLON_HTML . '</li></ul><p>z</p>',
            $this->html("- +\n" . self::COLON . "\n\nz\n"),
        );
    }

    // ------------------------------------------------------------- block quote

    public function testTheBlockQuoteCollectorKeepsACodeFenceWhole(): void
    {
        $this->assertSame(
            '<blockquote><p>q</p>' . self::CODE_HTML . '</blockquote><p>z</p>',
            $this->html("> q\n+\n" . self::CODE . "\n\nz\n"),
        );
    }

    public function testTheBlockQuoteCollectorKeepsAColonFenceWhole(): void
    {
        $this->assertSame(
            '<blockquote><p>q</p>' . self::COLON_HTML . '</blockquote><p>z</p>',
            $this->html("> q\n+\n" . self::COLON . "\n\nz\n"),
        );
    }

    public function testTheBlockQuoteCollectorKeepsACommentFenceWhole(): void
    {
        $this->assertSame(
            '<blockquote><p>q</p></blockquote><p>z</p>',
            $this->html("> q\n+\n" . self::COMMENT . "\n\nz\n"),
        );
    }

    // ---------------------------------------------------------- footnote body

    public function testTheFootnoteCollectorKeepsACodeFenceWhole(): void
    {
        $this->assertSame(
            $this->note(self::CODE_HTML),
            $this->html("[^f]: n\n+\n" . self::CODE . "\n\nsee[^f]\n"),
        );
    }

    public function testTheFootnoteCollectorKeepsAColonFenceWhole(): void
    {
        $this->assertSame(
            $this->note(self::COLON_HTML),
            $this->html("[^f]: n\n+\n" . self::COLON . "\n\nsee[^f]\n"),
        );
    }

    /**
     * A DEFINITION LINE inside the fence body is body text and defines nothing.
     * §28 makes a fence body verbatim and §17 L6 collection cannot reach into
     * it - and L3's boundary list does not name a definition line at all. This
     * is corpus category 279's own first row.
     *
     * The PASS 1 collector and the PASS 2 skip must consume the SAME lines: if
     * the skip stops where the collector did not, the line renders twice. That
     * makes this row the one that fails when the two mirrors drift apart.
     */
    public function testTheFootnoteCollectorKeepsADefinitionLineAsFenceBody(): void
    {
        $this->assertSame(
            $this->note('<pre><code>a [^z]: zz b </code></pre>'),
            $this->html("[^f]: n\n+\n```\na\n[^z]: zz\nb\n```\n\nsee[^f]\n"),
        );
    }

    // -------------------------------------------------------- definition body

    public function testTheDefinitionBodyCollectorKeepsACodeFenceWhole(): void
    {
        $this->assertSame(
            '<dl><dt>t</dt><dd><p>d</p>' . self::CODE_HTML . '</dd></dl><p>z</p>',
            $this->html(":: t\n:  d\n+\n" . self::CODE . "\n\nz\n"),
        );
    }

    public function testTheDefinitionBodyCollectorKeepsAColonFenceWhole(): void
    {
        $this->assertSame(
            '<dl><dt>t</dt><dd><p>d</p>' . self::COLON_HTML . '</dd></dl><p>z</p>',
            $this->html(":: t\n:  d\n+\n" . self::COLON . "\n\nz\n"),
        );
    }

    public function testTheFirstBlockDefinitionCollectorKeepsACodeFenceWhole(): void
    {
        $this->assertSame(
            '<dl><dt>t</dt><dd>' . self::CODE_HTML . '</dd></dl><p>z</p>',
            $this->html(":: t\n:  +\n" . self::CODE . "\n\nz\n"),
        );
    }

    public function testTheFirstBlockDefinitionCollectorKeepsAColonFenceWhole(): void
    {
        $this->assertSame(
            '<dl><dt>t</dt><dd>' . self::COLON_HTML . '</dd></dl><p>z</p>',
            $this->html(":: t\n:  +\n" . self::COLON . "\n\nz\n"),
        );
    }

    // ------------------------------------------------- the item's INDENTED body

    /**
     * Not a `+` path: the indented body is collected line by line against a
     * running tracker, and the boundary at issue is the sibling marker rather
     * than the blank. §24 S1/S2 place a line by the column it REACHES and never
     * by its first character, so a marker at the body's own column is inside
     * the open container and does not end the item.
     */
    public function testTheIndentedBodyKeepsAColonFenceWholeAcrossAMarker(): void
    {
        $this->assertSame(
            '<ul><li>x <div><p>a - m b</p></div></li></ul>',
            $this->html("- x\n  :::\n  a\n  - m\n  b\n  :::\n"),
        );
    }

    public function testTheIndentedBodyKeepsACodeFenceWholeAcrossAMarker(): void
    {
        $this->assertSame(
            '<ul><li>x <pre><code>a - m b </code></pre></li></ul>',
            $this->html("- x\n  ```\n  a\n  - m\n  b\n  ```\n"),
        );
    }

    /**
     * markup-carve/carve#985, the comment spelling, WITH ONE WORD OF LEAD TEXT
     * on the item. A blank inside the item's own indented `%%%` body is that
     * body's content: it neither ends the item nor loosens it, so the item is
     * TIGHT and holds only its lead word.
     */
    public function testABlankInsideAnIndentedCommentFenceDoesNotLoosenTheItem(): void
    {
        $this->assertSame(
            '<ul><li>x</li></ul>',
            $this->html("- x\n  %%%\n  a\n\n  b\n  %%%\n"),
        );
    }

    /**
     * markup-carve/carve#985, the colon spelling, beside the comment one. This
     * engine already answered this row correctly; it is here so the pair is
     * measured together rather than one kind at a time, which is the reading
     * error the whole class is made of.
     */
    public function testABlankInsideAnIndentedColonFenceDoesNotLoosenTheItem(): void
    {
        $this->assertSame(
            '<ul><li>x <div><p>a</p><p>b</p></div></li></ul>',
            $this->html("- x\n  :::\n  a\n\n  b\n  :::\n"),
        );
    }

    public function testAMarkerLineCommentFenceKeepsItsBlank(): void
    {
        $this->assertSame('<ul><li></li></ul>', $this->html("- %%%\n  a\n\n  b\n  %%%\n"));
    }

    // ------------------------------------------- what the fence scan nests through
    //
    // The attached block's extent is found by walking the fence structure, so
    // the nesting rules that walk asks about are behavior of this change and
    // are pinned here rather than left to the two shapes the corpus happens to
    // carry.

    /**
     * A CODE fence inside the attached colon body is opaque, so the `:::` line
     * in its body is code text and closes nothing. Without the skip the div
     * would end three lines early, at a line §28 makes verbatim.
     *
     * @return void
     */
    public function testAColonFenceNestsThroughACodeFenceInItsBody(): void
    {
        $this->assertSame(
            '<ul><li>x <aside class="admonition note"><p>a</p><pre><code>::: </code></pre><p>b</p>'
                . '</aside></li></ul><p>z</p>',
            $this->html("- x\n+\n::: note\na\n```\n:::\n```\n\nb\n:::\n\nz\n"),
        );
    }

    /**
     * And a COMMENT fence in the same position, on the same reading.
     *
     * @return void
     */
    public function testAColonFenceNestsThroughACommentFenceInItsBody(): void
    {
        $this->assertSame(
            '<ul><li>x <aside class="admonition note"><p>a</p><p>b</p></aside></li></ul><p>z</p>',
            $this->html("- x\n+\n::: note\na\n%%%\n:::\n%%%\n\nb\n:::\n\nz\n"),
        );
    }

    /**
     * A colon fence closes on an EXACT length match (markup-carve/carve#455),
     * so the widths in flight are a stack: a WIDER inner run opens rather than
     * closes, and the outer fence's own width has to reappear for the attached
     * block to end.
     *
     * @return void
     */
    public function testAWiderColonRunNestsInsideTheAttachedColonFence(): void
    {
        $this->assertSame(
            '<ul><li>x <aside class="admonition note"><div class="inner"><p>a</p><p>b</p></div>'
                . '</aside></li></ul><p>z</p>',
            $this->html("- x\n+\n::: note\n:::: inner\na\n\nb\n::::\n:::\n\nz\n"),
        );
    }

    /**
     * A BARE wider run nests too - it is not the outer fence's closer, because
     * a colon fence closes on an EXACT match and nothing else.
     *
     * @return void
     */
    public function testABareWiderColonRunNestsRatherThanCloses(): void
    {
        $this->assertSame(
            '<ul><li>x <aside class="admonition note"><div><p>a</p><p>b</p></div>'
                . '</aside></li></ul><p>z</p>',
            $this->html("- x\n+\n::: note\n::::\na\n\nb\n::::\n:::\n\nz\n"),
        );
    }

    /**
     * A code opener with an INFO STRING is not closer-shaped, so a document
     * carrying only such openers records no code closer at all and the fence
     * is refuted without a scan. The attached block then falls back to the
     * boundary set, which is the unterminated answer.
     *
     * @return void
     */
    public function testAnInfoStringFenceWithNoCloserAnywhereFallsBack(): void
    {
        $this->assertSame(
            '<ul><li>x <pre><code class="language-js">a </code></pre></li></ul><p>b</p>',
            $this->html("- x\n+\n```js\na\n\nb\n"),
        );
    }

    /**
     * THE INDEX ONLY EVER REFUTES. It is deliberately permissive, so a `:::`
     * line sitting inside a code fence still records a colon closer - and the
     * real walk, which skips that body whole, correctly finds none. A positive
     * answer from the index is a reason to scan, never an answer.
     *
     * @return void
     */
    public function testAColonCloserHiddenInsideACodeFenceDoesNotCloseTheAttachedBlock(): void
    {
        $this->assertSame(
            '<ul><li>x <aside class="admonition note"><pre><code>::: </code></pre></aside></li></ul><p>b</p>',
            $this->html("- x\n+\n::: note\n```\n:::\n```\n\nb\n"),
        );
    }

    /**
     * A TYPED opener of the same width is an opener too, not the closer its
     * bare run would be, so it nests and the first bare run closes it.
     *
     * @return void
     */
    public function testATypedColonOpenerNestsAtTheSameWidth(): void
    {
        $this->assertSame(
            '<ul><li>x <aside class="admonition note"><div class="warn"><p>a</p><p>b</p></div>'
                . '</aside></li></ul><p>z</p>',
            $this->html("- x\n+\n::: note\n::: warn\na\n\nb\n:::\n:::\n\nz\n"),
        );
    }

    /**
     * An attached COLON fence with no closer is the unterminated case too: the
     * scan falls back to the boundary set, so the blank ends the attachment and
     * the tail is a document paragraph. Left where it was for the same reason
     * the code spelling is.
     *
     * @return void
     */
    public function testAnUnterminatedAttachedColonFenceFallsBackToTheBoundary(): void
    {
        $this->assertSame(
            '<ul><li>x <aside class="admonition note"><p>a</p></aside></li></ul><p>b</p>',
            $this->html("- x\n+\n::: note\na\n\nb\n"),
        );
    }

    /**
     * An attached COMMENT fence with no closer is the unterminated case too.
     *
     * @return void
     */
    public function testAnUnterminatedAttachedCommentFenceFallsBackToTheBoundary(): void
    {
        // An opener with no closer opens no comment block (PART 9 section 28),
        // so `a` is ordinary item text rather than a hidden body, and the blank
        // still ends the attachment.
        $this->assertSame('<ul><li>x a </li></ul><p>b</p>', $this->html("- x\n+\n%%%\na\n\nb\n"));
    }

    /**
     * AN INDENTED CLOSER DOES NOT CLOSE A FLUSH-LEFT ATTACHED CODE FENCE. The
     * index is permissive about a leading run, so it records this line and
     * reports a closer as possible - and the real scan, which reads the view
     * the block is parsed in, correctly finds none. Refuting and answering are
     * different jobs, and only the scan does the second.
     *
     * @return void
     */
    public function testAnIndentedCodeCloserDoesNotCloseAQuoteAttachedFence(): void
    {
        // No closer is found, so the attached block ends at the blank instead
        // - and the fence, still unterminated, takes the indented line as its
        // own content.
        $this->assertSame(
            '<blockquote><p>q</p><pre><code>a ``` </code></pre></blockquote><p>b</p>',
            $this->html("> q\n+\n```\na\n  ```\n\nb\n"),
        );
    }

    /**
     * A fence whose closer would be past END OF INPUT never closes either.
     *
     * @return void
     */
    public function testAnAttachedCodeFenceRunningToEndOfInputFallsBack(): void
    {
        $this->assertSame('<ul><li>x <pre><code>a </code></pre></li></ul>', $this->html("- x\n+\n```\na\n"));
    }

    /**
     * TWO DISTINCT CODE WIDTHS in one document. A code closer matches at the
     * opener's length OR LONGER, so the index answers "is there a closer for
     * this width" over a suffix maximum rather than an exact key - and with one
     * width recorded that lookup is never asked to choose.
     *
     * @return void
     */
    public function testTwoAttachedCodeFencesOfDifferentWidthsBothStayWhole(): void
    {
        $this->assertSame(
            '<ul><li><p>x</p><pre><code>a b </code></pre></li><li><p>y</p><pre><code>c d </code></pre>'
                . '</li></ul><p>z</p>',
            $this->html("- x\n+\n`````\na\n\nb\n`````\n\n- y\n+\n```\nc\n\nd\n```\n\nz\n"),
        );
    }

    /**
     * A `+` with NOTHING after it attaches nothing and must not read past the
     * end of the line set.
     *
     * @return void
     */
    public function testAContinuationMarkerAtEndOfInputAttachesNothing(): void
    {
        $this->assertSame('<ul><li>x</li></ul>', $this->html("- x\n+\n"));
    }

    // ------------------------------------------------------------------ controls
    //
    // Each of these holds byte-identically BEFORE the fix. They pin the part of
    // L3 the fix must NOT move, so a mutation that reverts one collector leaves
    // them green while that collector's own rows go red.

    /**
     * THE GENUINE-LOOSE CONTROL. Without it a pass proves nothing: an engine
     * that simply stopped loosening items would satisfy every looseness row
     * above. A blank line between an item's own blocks still loosens the list.
     */
    public function testAGenuineBlankStillLoosensTheItem(): void
    {
        $this->assertSame('<ul><li><p>x</p><p>y</p></li></ul>', $this->html("- x\n\n  y\n"));
    }

    public function testAnUnfencedAttachedBlockStillEndsAtTheBlankLine(): void
    {
        // The boundary rule L3 states is intact; the fix only stops it reaching
        // INSIDE a block. Without this the change could have been "attach
        // everything", which L3 does not say.
        $this->assertSame('<ul><li>x p </li></ul><p>z</p>', $this->html("- x\n+\np\n\nz\n"));
    }

    /**
     * An UNTERMINATED fence is left where it was: with no closer there is no
     * fenced block to run through, so the scan falls back to the boundary set.
     * No clause names the unterminated case for an attached block, so the fix
     * does not invent one.
     */
    public function testAnUnterminatedFenceStillEndsAtTheBlankLine(): void
    {
        $this->assertSame(
            '<ul><li>x <pre><code>a </code></pre></li></ul><p>z</p>',
            $this->html("- x\n+\n```\na\n\nz\n"),
        );
    }

    /**
     * AND AN UNTERMINATED OPENER MUST NOT LATCH THE COMMENT TRACKER. `%%% x`
     * has no closer, so it opens nothing (§28) and the blank below it still
     * ends the item's paragraph - the tracker's lookahead must not count the
     * opener as its own closer, which is the defect that made every
     * unterminated comment opener swallow the rest of the document.
     */
    public function testAnUnterminatedCommentOpenerDoesNotSwallowTheDocument(): void
    {
        $html = $this->html("- a\n  %%% x\n # h\n\n- b\n");

        $this->assertStringContainsString('<p>a</p>', $html);
        $this->assertStringContainsString('<p># h</p>', $html);
        $this->assertStringContainsString('<p>b</p>', $html);
    }

    /**
     * THE CLOSER INDEX IS A SUPERSET OF WHAT THE CLOSER TESTS MATCH, which is
     * the invariant that lets it REFUTE. An index NARROWER than the tests misses
     * a line the tests would close on, declares the fence unterminated and hands
     * the body back to the boundary set, which splits it. Raised by codex review
     * when the index was spelled `[ \t]*` against `\s*` tests.
     *
     * Both are now PART 7's four characters, which keeps the invariant by making
     * them EQUAL rather than by making the index wider. The `\v` row moved with
     * that: under ONE WHITESPACE DEFINITION, IN EVERY CONSTRUCT a VERTICAL TAB is
     * CONTENT, so ```` ```<VT> ```` is not a closer at all and the fence is
     * genuinely unterminated (markup-carve/carve#963).
     *
     * The pairing is what the case is for. A vertical tab and an ordinary
     * content character must produce the SAME document; if only the closer tests
     * narrow and the index does not, the two rows still agree here, so the
     * padded-with-a-space row below is what holds the index to the tests.
     *
     * @return void
     */
    public function testACloserPaddedWithAVerticalTabDoesNotCloseTheAttachedFence(): void
    {
        // html() collapses a whitespace run, which erases the probe character
        // from the OUTPUT, so the two documents are compared by shape rather
        // than by folding the character to a sentinel.
        $this->assertSame(
            '<ul><li>x <pre><code>a </code></pre></li></ul><p>b <code></code></p><p>z</p>',
            $this->html("- x\n+\n```\na\n\nb\n```\v\n\nz\n"),
        );
        $this->assertNotSame(
            '<ul><li>x ' . self::CODE_HTML . '</li></ul><p>z</p>',
            $this->html("- x\n+\n```\na\n\nb\n```\v\n\nz\n"),
        );
        $this->assertNotSame(
            '<ul><li>x ' . self::COLON_HTML . '</li></ul><p>z</p>',
            $this->html("- x\n+\n::: note\na\n\nb\n:::\v\n\nz\n"),
        );
    }

    /**
     * The other half of the invariant, and the one that still exercises it: a
     * closer padded with real whitespace DOES close, so the index must reach it.
     * Narrow the index below the tests and this splits.
     *
     * @return void
     */
    public function testACloserPaddedWithASpaceOrTabStillClosesTheAttachedFence(): void
    {
        $this->assertSame(
            '<ul><li>x ' . self::CODE_HTML . '</li></ul><p>z</p>',
            $this->html("- x\n+\n```\na\n\nb\n```  \n\nz\n"),
        );
        $this->assertSame(
            '<ul><li>x ' . self::COLON_HTML . '</li></ul><p>z</p>',
            $this->html("- x\n+\n::: note\na\n\nb\n:::\t\n\nz\n"),
        );
    }

    public function testAnAttachedBlockStillEndsAtASiblingMarker(): void
    {
        $this->assertSame('<ul><li>x p </li><li>y</li></ul>', $this->html("- x\n+\np\n- y\n"));
    }

    public function testAnAttachedBlockStillEndsAtAFurtherPlus(): void
    {
        $this->assertSame('<ul><li>x p q </li></ul>', $this->html("- x\n+\np\n+\nq\n"));
    }

    public function testAQuoteAttachedBlockStillEndsAtAQuoteLine(): void
    {
        $this->assertSame(
            '<blockquote><p>q</p><p>p</p><p>r</p></blockquote>',
            $this->html("> q\n+\np\n> r\n"),
        );
    }
}
