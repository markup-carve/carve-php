<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * `+` is ONE operation in every container (PART 9 section 17 L3/L4,
 * markup-carve/carve#1782): ownership of the NEXT flush-left block passes to
 * the container, one block, whatever kind it is.
 *
 * The reach was measured in four places and narrowed in two. A list item and a
 * block quote took one block; a footnote body and a definition description took
 * everything up to the boundary, so L3's own example gave the quote to the note
 * while the same document one container over left it outside the item. The
 * quote additionally treated a following `>` line as a boundary, so the marker
 * attached NOTHING there - the one thing the clause says it never does.
 *
 * Pinned upstream by corpus category 427.
 */
class TheContinuationMarkerAttachesOneBlockInEveryContainerTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    public function testAListItemTakesTheParagraphAndLeavesTheQuoteOutside(): void
    {
        $html = $this->converter->convert("- a\n+\npara\n> q\n");

        $this->assertSame(
            "<ul>\n  <li>a\n    para\n  </li>\n</ul>\n<blockquote><p>q</p></blockquote>\n",
            $html,
        );
    }

    public function testAFootnoteBodyTakesOneBlockToo(): void
    {
        $html = $this->converter->convert("[^n]: a\n+\npara\n> q\n\nsee[^n]\n");

        $this->assertStringContainsString("<p>a</p>\n      <p>para", $html);
        $this->assertStringNotContainsString("<blockquote><p>q</p></blockquote>\n      <p><a href=\"#fnref1\"", $html);
    }

    public function testADefinitionDescriptionTakesOneBlockToo(): void
    {
        $html = $this->converter->convert(":: t\n:  a\n+\npara\n> q\n");

        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <p>para</p>\n  </dd>\n</dl>\n<blockquote><p>q</p></blockquote>\n",
            $html,
        );
    }

    public function testASecondBlockTakesASecondMarker(): void
    {
        $html = $this->converter->convert(":: t\n:  a\n+\npara\n+\n> q\n");

        $this->assertStringContainsString("<p>para</p>\n    <blockquote><p>q</p></blockquote>", $html);
    }

    public function testAQuoteAttachedToAQuoteNests(): void
    {
        $html = $this->converter->convert("> a\n+\n> q\n");

        $this->assertSame(
            "<blockquote>\n  <p>a</p>\n  <blockquote><p>q</p></blockquote>\n</blockquote>\n",
            $html,
        );
    }

    public function testAQuoteLineUnderAnAttachedParagraphContinuesTheOuterQuote(): void
    {
        $html = $this->converter->convert("> a\n+\npara\n> q\n");

        $this->assertSame(
            "<blockquote>\n  <p>a</p>\n  <p>para</p>\n  <p>q</p>\n</blockquote>\n",
            $html,
        );
    }
}
