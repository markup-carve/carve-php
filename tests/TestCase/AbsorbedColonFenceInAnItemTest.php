<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A colon fence that fails PART 9 §12's opener test opens nothing, so the item's
 * paragraph is still open and PART 1 S4 folds the flush-left line below it in.
 *
 *     - item
 *       :::note
 *       body
 *       :::
 *     tail
 *
 * `:::note` is not an opener - a type word must be separated from the fence - so
 * it is paragraph text, and §12 then has the paragraph absorb the trailing `:::`
 * as text too. Nothing interrupted the paragraph, so it is open when `tail`
 * arrives (carve#891, corpus `86-list-lazy-continuation-9`).
 *
 * THREE CHANGES, and each one alone leaves the shape wrong:
 *
 * 1. the collector's `sawIndentedUnclaimedColonFence` latch is gone. It ended
 *    the item whenever a colon-fence-shaped line had been collected, which is a
 *    decision about the SHAPE of a line rather than about whether a block was
 *    opened - and it fired even for `- item` / `:::note` / `tail`, where there
 *    is no second fence at all.
 * 2. the trailing-block tracker learned §12: a malformed fence arms absorption,
 *    the next BARE run is text, a valid opener still interrupts, and inside an
 *    open container nothing arms, because the bare run there is a closer.
 * 3. `inDiv` no longer keeps the item collecting. An unterminated `:::` inside
 *    an item used to swallow the flush-left line INTO the div; the comment
 *    justifying it cited a §10 closer lookahead that carve#439 removed.
 *
 * The neighbouring shapes are here rather than in three other files because they
 * are consequences of one reading, and an implementation can get the first one
 * right for the wrong reason.
 */
class AbsorbedColonFenceInAnItemTest extends TestCase
{
    protected function html(string $source): string
    {
        return trim(CarveConverter::create()->convert($source));
    }

    public function testTheFlushLeftLineFoldsBecauseTheFenceOpenedNothing(): void
    {
        $this->assertSame(
            "<ul>\n  <li>item\n:::note\nbody\n:::\ntail</li>\n</ul>",
            $this->html("- item\n  :::note\n  body\n  :::\ntail\n"),
        );
    }

    public function testAValidOpenerClosesTheItemInstead(): void
    {
        // One space between the fence and the type word decides which of the two
        // answers the same five lines get: a real admonition opens, its closer
        // completes it, and a closed block leaves no open paragraph.
        $this->assertSame(
            "<ul>\n  <li>item\n    <aside class=\"admonition note\">\n      <p>body</p>\n    </aside>\n  </li>\n</ul>\n<p>tail</p>",
            $this->html("- item\n  ::: note\n  body\n  :::\ntail\n"),
        );
    }

    public function testALazyLineOneColumnInFoldsToo(): void
    {
        $this->assertSame(
            "<ul>\n  <li>item\n:::note\nbody\n:::\ntail</li>\n</ul>",
            $this->html("- item\n  :::note\n  body\n  :::\n tail\n"),
        );
    }

    public function testTheMalformedFenceMayBeTheParagraphsFirstLine(): void
    {
        $this->assertSame(
            "<ul>\n  <li>:::note\nbody\n:::\ntail</li>\n</ul>",
            $this->html("- :::note\n  body\n  :::\ntail\n"),
        );
    }

    public function testItFoldsInsideABlockQuote(): void
    {
        // The quote's prefix matches on the lazy line but the item's indentation
        // does not - the partial match S4 is written for.
        $this->assertSame(
            "<blockquote>\n  <ul>\n    <li>item\n:::note\nbody\n:::\ntail</li>\n  </ul>\n</blockquote>",
            $this->html("> - item\n>   :::note\n>   body\n>   :::\n> tail\n"),
        );
    }

    public function testAMalformedFenceWithNoSecondFenceStillFolds(): void
    {
        // The latch fired on the first fence-shaped line alone, so this shape -
        // which has no closer to absorb at all - ended the item too.
        $this->assertSame(
            "<ul>\n  <li>item\n:::note\ntail</li>\n</ul>",
            $this->html("- item\n  :::note\ntail\n"),
        );
    }

    public function testAWiderBareFenceIsAbsorbedToo(): void
    {
        // §12: "the absorption is not width-tagged". A malformed opener has no
        // length to match against.
        $this->assertSame(
            "<ul>\n  <li>item\n:::note\nbody\n::::\ntail</li>\n</ul>",
            $this->html("- item\n  :::note\n  body\n  ::::\ntail\n"),
        );
    }

    public function testAValidOpenerAfterTheMalformedOneStillInterrupts(): void
    {
        // Absorption covers a BARE run only. `::: note` opens its block, the
        // `:::` below is that block's closer, and a closed block leaves no open
        // paragraph - the same answer this engine gives at the top level.
        $this->assertSame(
            "<ul>\n  <li>item\n:::note\n    <aside class=\"admonition note\">\n      <p>body</p>\n    </aside>\n  </li>\n</ul>\n<p>tail</p>",
            $this->html("- item\n  :::note\n  ::: note\n  body\n  :::\ntail\n"),
        );
    }

    public function testAMalformedFenceInsideAnOpenContainerArmsNothing(): void
    {
        // The bare run under it is the admonition's closer, so absorbing it
        // would hold the paragraph open past the block's end.
        $this->assertSame(
            "<ul>\n  <li>item\n    <aside class=\"admonition note\">\n      <p>:::oops</p>\n    </aside>\n  </li>\n</ul>\n<p>tail</p>",
            $this->html("- item\n  ::: note\n  :::oops\n  :::\ntail\n"),
        );
    }

    public function testAHeadingOrATableEndsTheAbsorbingParagraph(): void
    {
        // Absorption belongs to ONE paragraph, so the `:::` below the heading is
        // a real div opener and `tail` ends the item.
        $this->assertSame(
            "<ul>\n  <li>item\n:::note\n    <h1 id=\"h\">h</h1>\n    <div>\n    </div>\n  </li>\n</ul>\n<p>tail</p>",
            $this->html("- item\n  :::note\n  # h\n  :::\ntail\n"),
        );
        $this->assertSame(
            "<ul>\n  <li>item\n:::note\n    <table>\n      <tbody>\n        <tr><td>a</td></tr>\n      </tbody>\n    </table>\n    <div>\n    </div>\n  </li>\n</ul>\n<p>tail</p>",
            $this->html("- item\n  :::note\n  | a |\n  :::\ntail\n"),
        );
    }

    public function testADivThatCLOSEDLeavesTheDepthWhereItStarted(): void
    {
        // A malformed fence AFTER a closed div arms absorption again, because
        // no container is open at that point. The depth counter has to come back
        // down on the closer for that to be true - left unbalanced, the bare run
        // below read as a phantom closer and the item ended. Measured against
        // the same block sequence at the top level, which gives one paragraph
        // holding `:::oops`, `:::` and `tail`.
        $this->assertSame(
            "<ul>\n  <li>item\n    <aside class=\"admonition note\">\n\n    </aside>\n    :::oops\n:::\ntail\n  </li>\n</ul>",
            $this->html("- item\n  ::: note\n  :::\n  :::oops\n  :::\ntail\n"),
        );
    }

    public function testAnUnterminatedDivDoesNotSwallowTheFlushLeftLine(): void
    {
        // The third change, on its own shape: an open div is not an open
        // paragraph, so the item ends and the line belongs to the document.
        $this->assertSame(
            "<ul>\n  <li>item\n    <aside class=\"admonition note\">\n      <p>body</p>\n    </aside>\n  </li>\n</ul>\n<p>tail</p>",
            $this->html("- item\n  ::: note\n  body\ntail\n"),
        );
    }
}
