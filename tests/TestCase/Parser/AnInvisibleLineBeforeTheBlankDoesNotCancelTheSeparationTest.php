<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * §17 L1b, in the MIRROR order: the invisible line BEFORE the blank
 * (markup-carve/carve#1808, corpus 429; markup-carve/carve-php#1799).
 *
 * L1b's argument never mentions which line came first - a line that renders
 * nothing "is not a separator, so the separation it appears to interrupt is
 * intact" - so `para` and `more` are two paragraphs with a blank line between
 * them either way the two lines are ordered, and the item is LOOSE. Corpus 186
 * pins the other order, and this build always got that one right, which is what
 * makes this a spelling gap rather than a rule disagreement.
 *
 * ONLY THE FOOTNOTE SPELLING WAS WRONG, and the mechanism says why. The item
 * collector crosses a blank line while an opaque region is open - a fence, a
 * div, a comment, a footnote body - so the region's own blocks stay in one
 * stream (markup-carve/carve-php#1787). The footnote arm was a bare LATCH: it
 * crossed whether or not the note's body actually continued past the blank. The
 * crossing then kept the blank inside the item's own stream, which is what
 * silences the L1b branch that would have loosened the list. The link-reference
 * and attribute spellings have no state to latch, so they were loose all along.
 *
 * The arm now asks the question its neighbour asks: does the body RESUME after
 * the blank, at the note's own column measured from the item's content column?
 */
class AnInvisibleLineBeforeTheBlankDoesNotCancelTheSeparationTest extends TestCase
{
    protected function html(string $source): string
    {
        return rtrim((new CarveConverter())->convert($source), "\n");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invisibleKinds(): array
    {
        return [
            'comment' => ['%% c'],
            'link reference definition' => ['[r]: /u'],
            'footnote definition' => ['[^f]: n'],
        ];
    }

    #[DataProvider('invisibleKinds')]
    public function testTheItemIsLooseWhicheverInvisibleKindPrecedesTheBlank(string $line): void
    {
        $this->assertSame(
            "<ul>\n  <li><p>para</p>\n    <p>more</p>\n  </li>\n</ul>",
            $this->html("- para\n  " . $line . "\n\n  more\n"),
        );
    }

    /**
     * The attribute kind shows that the separation and the PENDING METADATA are
     * independent axes: the item is loose for the same reason as the other
     * three, and §15 A2 FLOAT FORWARD carries `{.k}` across the blank onto the
     * second paragraph.
     */
    public function testTheAttributeKindAlsoCarriesItsPendingMetadataAcross(): void
    {
        $this->assertSame(
            "<ul>\n  <li><p>para</p>\n    <p class=\"k\">more</p>\n  </li>\n</ul>",
            $this->html("- para\n  {.k}\n\n  more\n"),
        );
    }

    /**
     * The order corpus 186 pins, which this build always answered - kept here
     * because a fix that inverted the two orders would pass the rows above and
     * fail this one.
     */
    public function testAControlTheOtherOrderIsStillLoose(): void
    {
        $this->assertSame(
            "<ul>\n  <li><p>para</p>\n    <p>more</p>\n  </li>\n</ul>",
            $this->html("- para\n\n  %% c\n  more\n"),
        );
    }

    /**
     * AND THE CROSSING ITSELF MUST SURVIVE. When the note's body really does
     * continue past the blank - written at the note's own column - the blank is
     * the BODY's internal separator and the lines stay in one stream, which is
     * what markup-carve/carve-php#1787 fixed. Narrowing the latch must not
     * take that with it, so the note comes out with two paragraphs.
     */
    public function testAControlABodyThatRealSpansTheBlankStillCrossesIt(): void
    {
        $html = $this->html("- para\n  [^f]: n\n\n    second\n\nSee[^f]\n");

        $this->assertStringContainsString('<p>n</p>', $html, $html);
        $this->assertStringContainsString('second', $html, $html);
    }
}
