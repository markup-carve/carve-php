<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve-php#1800, spec markup-carve/carve#1784 (PART 9R R7,
 * PART 12 section 23).
 *
 * Block-image status is a property of the RESOLVED tree, not of the source
 * line. `![a][r]` is a block image where `[r]: /u` is written and ordinary
 * paragraph text where it is not, and the definition may sit anywhere in the
 * document.
 *
 * ONE promotion phase settles it after reference resolution, and it is the
 * only place that binds an image caption. Until it runs, a `^ ` line below an
 * image paragraph is an UNBOUND SLOT: not a caption, and not paragraph text.
 * The phase binds it where the paragraph is promoted, and hands its source
 * lines back - ALL of them - where it is not.
 *
 * The two give-back paths below are the ones on which a line of the document
 * can be lost: a slot MORE THAN ONE LINE wide, and a slot INSIDE A CONTAINER.
 * Corpus category 434 pins each with its resolved control beside it.
 */
class BlockImageIsAResolvedTreePropertyTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    private function html(string $source): string
    {
        return trim($this->converter->convert($source));
    }

    public function testResolvedWithNoCaptionIsABareBlockImage(): void
    {
        $this->assertSame('<img src="/u" alt="a">', $this->html("![a][r]\n\n[r]: /u\n"));
    }

    public function testResolvedWithACaptionIsAFigure(): void
    {
        $this->assertSame(
            "<figure>\n  <img src=\"/u\" alt=\"a\">\n  <figcaption>cap</figcaption>\n</figure>",
            $this->html("![a][r]\n^ cap\n\n[r]: /u\n"),
        );
    }

    public function testUnresolvedWithNoCaptionIsAnOrdinaryParagraph(): void
    {
        $this->assertSame('<p>![a][r]</p>', $this->html("![a][r]\n"));
    }

    /**
     * The row that decides the model. Binding the caption on the source shape
     * would put a `<figure>` around a paragraph of literal `![a][r]`, which no
     * engine writes.
     */
    public function testUnresolvedWithACaptionGivesTheSlotBackAsParagraphText(): void
    {
        $this->assertSame("<p>![a][r]\n^ cap</p>", $this->html("![a][r]\n^ cap\n"));
    }

    /**
     * EVERY line of the slot, not the marker line alone. Handing back only the
     * first line loses `continued` from the document.
     */
    public function testGivesBackEveryLineOfAMultiLineSlot(): void
    {
        $this->assertSame(
            "<p>![a][r]\n^ cap one\ncontinued</p>",
            $this->html("![a][r]\n^ cap one\ncontinued\n"),
        );
    }

    public function testBindsTheWholeMultiLineSlotWhenTheReferenceResolves(): void
    {
        $this->assertSame(
            "<figure>\n  <img src=\"/u\" alt=\"a\">\n  <figcaption>cap one\ncontinued</figcaption>\n</figure>",
            $this->html("![a][r]\n^ cap one\ncontinued\n\n[r]: /u\n"),
        );
    }

    public function testGivesTheSlotBackInsideAListItem(): void
    {
        $this->assertSame(
            "<ul>\n  <li>![a][r]\n^ cap</li>\n</ul>",
            $this->html("- ![a][r]\n  ^ cap\n"),
        );
    }

    public function testBindsTheSlotInsideAListItemWhenTheReferenceResolves(): void
    {
        $this->assertSame(
            "<ul>\n  <li>\n    <figure>\n      <img src=\"/u\" alt=\"a\">\n"
            . "      <figcaption>cap</figcaption>\n    </figure>\n  </li>\n</ul>",
            $this->html("- ![a][r]\n  ^ cap\n\n[r]: /u\n"),
        );
    }

    public function testTheInlineFormInTheSamePositionKeepsItsCaption(): void
    {
        $this->assertSame(
            "<ul>\n  <li>\n    <figure>\n      <img src=\"/u\" alt=\"a\">\n"
            . "      <figcaption>cap</figcaption>\n    </figure>\n  </li>\n</ul>",
            $this->html("- ![a](/u)\n  ^ cap\n"),
        );
    }

    public function testBindsTheSlotInsideABlockQuoteWhenTheReferenceResolves(): void
    {
        $this->assertSame(
            "<blockquote>\n  <figure>\n    <img src=\"/u\" alt=\"a\">\n"
            . "    <figcaption>cap</figcaption>\n  </figure>\n</blockquote>",
            $this->html("> ![a][r]\n> ^ cap\n\n[r]: /u\n"),
        );
    }

    public function testGivesTheSlotBackInsideABlockQuote(): void
    {
        $this->assertSame(
            "<blockquote><p>![a][r]\n^ cap</p></blockquote>",
            $this->html("> ![a][r]\n> ^ cap\n"),
        );
    }
}
