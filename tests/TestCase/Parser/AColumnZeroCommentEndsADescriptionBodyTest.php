<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A `%%` comment at COLUMN 0 under a definition description ENDS the body
 * (markup-carve/carve-php#1802).
 *
 * It renders nothing, so it leaves no paragraph open, so it ends the body -
 * exactly the ruling markup-carve/carve#1809 gave the other four invisible
 * kinds. It is the same question, not a new one, and the clause needs no
 * sentence added for it. This build folded the comment in instead, and folded
 * everything after it in with it: the line after the comment became a SECOND
 * paragraph of a description that had already ended.
 *
 * THE COMMENT'S OPEN COLUMN IS A DIFFERENT QUESTION. markup-carve/carve#1783
 * records that a comment's own COLUMN stays open, unlike the other invisible
 * kinds, and that is real - but it decides which line CHOOSES the next line's
 * owner, not whether the comment itself ends a body. Only the first is affected
 * by the column staying open, so the exception does not reach this shape. The
 * column-exempt behaviour BELOW column 0 is a control here and does not move.
 *
 * WHY IT HAD TO BE REFUSED BEFORE COLLECTION, the same mechanism
 * markup-carve/carve-php#1798 fixed one line kind over: the flush-left
 * continuation branch folded the comment in as lazy text, and the trailing-block
 * tracker then read it on the NEXT line - so it ended the body one line late,
 * with the following line already inside the description.
 *
 * This was the last divergent cell of twenty in the below-the-column band.
 * Measured against carve-js `cc9bed84` and carve-rs `aea04297`: with the fix all
 * fourteen shapes in this file and its controls are byte-identical in all three.
 */
class AColumnZeroCommentEndsADescriptionBodyTest extends TestCase
{
    protected function html(string $source): string
    {
        return rtrim((new CarveConverter())->convert($source), "\n");
    }

    /**
     * The defect. `tail` is a document-level paragraph, not the description's
     * second one.
     */
    public function testTheBodyEndsAtTheComment(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>\n<p>tail</p>",
            $this->html(":: t\n:  d\n%% c\ntail\n"),
        );
    }

    /**
     * The blank-line spelling was already right, which is what makes the row
     * above a defect rather than a choice: one document cannot depend on a blank
     * line the comment renders nothing either side of.
     */
    public function testItAgreesWithTheBlankLineSpelling(): void
    {
        $this->assertSame(
            $this->html(":: t\n:  d\n\n%% c\ntail\n"),
            $this->html(":: t\n:  d\n%% c\ntail\n"),
        );
    }

    /**
     * The next block may be another definition list. The fold used to keep both
     * entries in ONE `dl`, which is the loudest spelling of the same defect: the
     * comment did not merely fail to end the body, it merged two lists.
     */
    public function testItEndsTheListBeforeAFollowingDefinitionList(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>\n<dl>\n  <dt>t2</dt>\n  <dd>d2</dd>\n</dl>",
            $this->html(":: t\n:  d\n%% c\n:: t2\n:  d2\n"),
        );
    }

    /**
     * The UNCLOSED fence is the second comment-shaped spelling at column 0. An
     * unclosed fence opens no block (PART 9 §28), so it never reached
     * `startsInterruptingBlock()` and fell into the fold with everything after
     * it. The CLOSED fence already ended the body and is a control below.
     */
    public function testAnUnclosedCommentFenceEndsTheBodyToo(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>\n<p>tail</p>",
            $this->html(":: t\n:  d\n%%%\ntail\n"),
        );
    }

    /**
     * With nothing after it the comment still ends the body, and the `dd` stays
     * tight - it never gained a paragraph to be loose about.
     */
    public function testWithNothingAfterItTheDescriptionStaysTight(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>",
            $this->html(":: t\n:  d\n%% c\n"),
        );
    }

    /**
     * CONTROL - the CLOSED fence already ended the body, through
     * `startsInterruptingBlock()`. It is here so a fix that reached for the
     * fence by widening that predicate instead is visible as a no-op on the row
     * it was supposed to be about.
     */
    public function testAControlAClosedCommentFenceAlreadyEndedTheBody(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>\n<p>tail</p>",
            $this->html(":: t\n:  d\n%%%\nx\n%%%\ntail\n"),
        );
    }

    /**
     * CONTROL - BELOW column 0 the comment is column-exempt and the body has
     * already ended through the guard above this branch (§24 C3, and the band's
     * own file pins it). Columns 1, 2 and 3 must all keep their current answer:
     * this fix is STRICTLY column 0, like the attribute line beside it.
     */
    public function testAControlBelowColumnZeroIsUnchanged(): void
    {
        foreach ([' ', '  ', '   '] as $indent) {
            $this->assertSame(
                "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>\n<p>tail</p>",
                $this->html(":: t\n:  d\n" . $indent . "%% c\ntail\n"),
                'indent ' . strlen($indent),
            );
        }
    }

    /**
     * CONTROL - a plain line at column 0 still FOLDS. The fix removes exactly
     * one more line kind from the fold; this is the row that says the fold is
     * still there. A fix that broke out of the branch unconditionally passes
     * every row above and fails this one.
     */
    public function testAControlAPlainLineAtColumnZeroStillFolds(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>d\nplain\ntail</dd>\n</dl>",
            $this->html(":: t\n:  d\nplain\ntail\n"),
        );
    }

    /**
     * CONTROL - a line that merely CONTAINS `%%` is not a comment line, so it
     * folds like any other text. The predicate is anchored, and this is the row
     * that keeps it anchored.
     */
    public function testAControlAnInlineCommentMidLineStillFolds(): void
    {
        $html = $this->html(":: t\n:  d\ntext %% c\ntail\n");

        $this->assertStringStartsWith("<dl>\n  <dt>t</dt>\n  <dd>d\ntext", $html, $html);
        $this->assertStringNotContainsString('</dl>', substr($html, 0, strpos($html, 'tail') ?: 0), $html);
    }

    /**
     * CONTROL - the attribute line at column 0 keeps ATTACHING rather than
     * merely ending the body (markup-carve/carve-php#1798). The two kinds sit in
     * the same condition one clause apart, and a fix that confused them loses
     * the class.
     */
    public function testAControlTheAttributeLineStillAttaches(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>d</dd>\n</dl>\n<p class=\"k\">tail</p>",
            $this->html(":: t\n:  d\n{.k}\ntail\n"),
        );
    }

    /**
     * CONTROL - the OTHER TWO HOSTS, neither of which moves.
     *
     * The block quote already ended at a column-0 comment. The list item does
     * NOT: a column-0 line is at the item's own base, so it is still the item's
     * lazy continuation and `tail` folds in. carve-js `cc9bed84` and carve-rs
     * `aea04297` both do exactly this, so the two hosts differing is the settled
     * answer and not a second defect - and pinning them here is what says the
     * change reached the description's flush-left branch and nothing else.
     */
    public function testAControlTheOtherTwoHostsDoNotMove(): void
    {
        $this->assertSame(
            "<ul>\n  <li>d\n    tail\n  </li>\n</ul>",
            $this->html("- d\n%% c\ntail\n"),
        );
        $this->assertSame(
            "<blockquote><p>d</p></blockquote>\n<p>tail</p>",
            $this->html("> d\n%% c\ntail\n"),
        );
    }
}
