<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\ListBlock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §17 L1, as markup-carve/carve#1633 narrowed it: a blank line inside an
 * item loosens it ONLY when a PARAGRAPH follows the blank.
 *
 * markup-carve/carve#1622 had been ruled with a wider wording - any block after
 * the blank loosens - and markup-carve/carve#1630 measured that wording against
 * the five already-pinned `323-a-block-attached-after-an-invisible-line-leaves-
 * the-item-tight` documents and found it wider than the finding it came from.
 *
 * THE MECHANISM IS markup-carve/carve#1266's. An attached block CONSUMES the
 * blank the gap held: a container has an opener to absorb the separation, so
 * nothing survives for L1 to read as a separator between the item's blocks. A
 * paragraph has no opener, so the blank survives and L1's blank-line-separated
 * second paragraph is exactly what is there. The dividing line is PARAGRAPH
 * versus every other block kind.
 *
 * carve-php read the interior of an attached `:::` container as the item's own
 * structure, so the paragraph after the container's OWN blank was counted as
 * the item's second block - one misattribution, and the item it loosened took
 * its siblings loose with it (markup-carve/carve-php#1657,
 * markup-carve/carve-php#1659). carve-rs had the identical defect from the
 * identical cause (markup-carve/carve-rs#1307).
 *
 * WHAT MAKES EACH CASE ABLE TO FAIL is stated per group below, because a
 * suppression fix reads as correct and as an over-correction from the same
 * "stopped loosening" signal.
 */
class ABlankLineLoosensAnItemOnlyWhenAParagraphFollowsItTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    /**
     * THE HALF THAT MUST STILL LOOSEN. Nothing here has a container in it, so a
     * fix that suppressed on the blank alone - rather than on what the blank is
     * separating - fails here first. Corpus
     * `409-a-blank-line-loosens-an-item-only-when-a-paragraph-follows-it`.
     *
     * @return array<string, array{string, string}>
     */
    public static function paragraphAfterTheBlank(): array
    {
        return [
            'a plain second paragraph' => [
                "- x\n\n  y\n- z\n",
                "<ul>\n  <li><p>x</p>\n    <p>y</p>\n  </li>\n  <li><p>z</p></li>\n</ul>\n",
            ],
            'a paragraph BELOW a closed container' => [
                "- x\n\n  ::: d\n  a\n  :::\n\n  b\n- z\n",
                "<ul>\n  <li><p>x</p>\n    <div class=\"d\">\n      <p>a</p>\n    </div>\n"
                    . "    <p>b</p>\n  </li>\n  <li><p>z</p></li>\n</ul>\n",
            ],
            // A colon run inside VERBATIM payload closes nothing. Read as the
            // closer it puts the span's end ABOVE the real one, so the walk
            // resumes on the code fence's own closing line, opens a fence
            // there and swallows the blank and the paragraph below the
            // container.
            'a paragraph below a container whose code block holds a colon run' => [
                "- x\n\n  ::: d\n  ```\n  :::\n  ```\n  :::\n\n  outside\n- z\n",
                "<ul>\n  <li><p>x</p>\n    <div class=\"d\">\n      <pre><code>:::\n</code></pre>\n"
                    . "    </div>\n    <p>outside</p>\n  </li>\n  <li><p>z</p></li>\n</ul>\n",
            ],
        ];
    }

    /**
     * The second row is the NEAR MISS this fix could have swallowed: the skip
     * has to jump the container's span and then keep walking, so the blank
     * BELOW the closer is still reached. A skip that latched - or that ran to
     * the end of the chunk from a closed opener - renders `b` bare here.
     */
    #[DataProvider('paragraphAfterTheBlank')]
    public function testAParagraphAfterTheBlankLoosensTheItem(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($source));
    }

    /**
     * THE HALF THAT MUST NOT LOOSEN. Every one of these renders `<li><p>x</p>`
     * and `<li><p>z</p></li>` before the fix, so each fails without it. The
     * sibling is in every expectation on purpose: the defect propagated, and
     * one item's wrong answer took the whole list loose with it.
     *
     * @return array<string, array{string, string}>
     */
    public static function attachedBlockAfterTheBlank(): array
    {
        return [
            // Corpus 409-...-2, the document carve-php was last engine out on.
            'a closed div, with a blank between its own two blocks' => [
                "- x\n\n  ::: d\n  a\n\n  b\n  :::\n- z\n",
                "<ul>\n  <li>x\n    <div class=\"d\">\n      <p>a</p>\n      <p>b</p>\n"
                    . "    </div>\n  </li>\n  <li>z</li>\n</ul>\n",
            ],
            // The SAME document with no closer. An explicit closer is a
            // spelling change tightness may not move across
            // (markup-carve/carve#1632), so the span runs to the end of the
            // chunk and the answer does not move.
            'an unterminated div, same interior blank' => [
                "- x\n\n  ::: d\n  a\n\n  b\n- z\n",
                "<ul>\n  <li>x\n    <div class=\"d\">\n      <p>a</p>\n      <p>b</p>\n"
                    . "    </div>\n  </li>\n  <li>z</li>\n</ul>\n",
            ],
            'an admonition, the second spelling of the same opener' => [
                "- x\n\n  ::: note\n  a\n\n  b\n  :::\n- z\n",
                "<ul>\n  <li>x\n    <aside class=\"admonition note\" aria-label=\"Note\">\n"
                    . "      <p>a</p>\n      <p>b</p>\n    </aside>\n  </li>\n  <li>z</li>\n</ul>\n",
            ],
            // Corpus 409-...-3, which already passed: the blockquote half of
            // the same rule, kept here so a fix aimed at the colon fence alone
            // cannot quietly break it.
            'a blockquote' => [
                "- x\n\n  > q\n- z\n",
                "<ul>\n  <li>x\n    <blockquote><p>q</p></blockquote>\n  </li>\n  <li>z</li>\n</ul>\n",
            ],
        ];
    }

    #[DataProvider('attachedBlockAfterTheBlank')]
    public function testAnAttachedBlockAfterTheBlankLeavesTheItemTight(
        string $source,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->converter->convert($source));
    }

    /**
     * The line block is the third spelling of the one opener
     * `parseDivFenceOpener()` answers for, and its rendering is not this
     * ticket's subject - so only the TIGHTNESS is asserted, on both items.
     */
    public function testTheLineBlockSpellingAgreesWithTheOtherTwo(): void
    {
        $html = $this->converter->convert("- x\n\n  ::: |\n  a\n\n  b\n  :::\n- z\n");

        $this->assertStringContainsString("<li>x\n", $html, 'the item holding the line block went loose');
        $this->assertStringContainsString('<li>z</li>', $html, 'the sibling was loosened by its neighbour');
    }

    /**
     * THE BOUND ON THE SKIP, and the reason it is not unconditional.
     *
     * A container that is the item's WHOLE body is not one block among several,
     * so its interior blank does separate two of the item's rendered blocks
     * (markup-carve/carve#1602). The identical five lines reach the looseness
     * scan in both shapes - only the caller knows whether a block precedes
     * them - which is why the scan is TOLD rather than left to read it off the
     * lines.
     *
     * Corpus 362-3. No HTML can show this: the item holds only the container,
     * so `<li>` wraps no paragraph either way and `list.tight` is the only
     * place the answer appears.
     */
    public function testAContainerThatIsTheWholeItemBodyStillLoosens(): void
    {
        $document = $this->converter->parse("- ::: d\n  b\n\n  tail\n");
        $list = $document->getChildren()[0];

        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertFalse($list->isTight(), 'the skip was widened past the block it was written for');
    }

    /**
     * The same container with a lead paragraph beside it is one block among
     * several, so the same interior blank leaves the item TIGHT. Corpus
     * `279-a-boundary-line-inside-an-open-fence-does-not-end-the-container-10`
     * and `362-an-unterminated-container-does-not-extend-the-item-past-a-blank-
     * line-5` are these two rows, closed and unterminated, and they are what
     * bounded the exemption above when it was measured.
     *
     * @return array<string, array{string}>
     */
    public static function containerBesideALead(): array
    {
        return [
            'closed' => ["- x\n  :::\n  a\n\n  b\n  :::\n"],
            'unterminated' => ["- x\n  :::\n  a\n\n  b\n"],
        ];
    }

    #[DataProvider('containerBesideALead')]
    public function testAContainerBesideALeadKeepsTheItemTight(string $source): void
    {
        $this->assertSame(
            "<ul>\n  <li>x\n    <div>\n      <p>a</p>\n      <p>b</p>\n    </div>\n  </li>\n</ul>\n",
            $this->converter->convert($source),
        );
    }

    /**
     * carve#326 C, unmoved: a blank inside VERBATIM payload is that block's own
     * content and never a separator, whether or not the fence is ever closed.
     * The colon skip is a second span kind beside this one, not a replacement
     * for it.
     *
     * @return array<string, array{string, string}>
     */
    public static function verbatimShapes(): array
    {
        return [
            'a closed code fence' => [
                "- x\n\n  ```\n  a\n\n  b\n  ```\n- z\n",
                "<ul>\n  <li>x\n    <pre><code>a\n\nb\n</code></pre>\n  </li>\n  <li>z</li>\n</ul>\n",
            ],
        ];
    }

    #[DataProvider('verbatimShapes')]
    public function testAnInteriorBlankInVerbatimPayloadDoesNotLoosen(
        string $source,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->converter->convert($source));
    }
}
