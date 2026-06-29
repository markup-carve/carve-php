<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * In a block quote's lazy continuation, a LIST MARKER folds into the open
 * quoted paragraph as literal text instead of ending the quote. This aligns the
 * quoted paragraph with the top-level paragraph rule, where a list marker does
 * not interrupt an open paragraph. The fold applies ONLY when an open plain
 * paragraph precedes the marker: after a heading (or any closed block) there is
 * no paragraph to fold into, so the marker ENDS the quote and starts a sibling
 * list, mirroring the top-level `# h\n- item` (heading plus sibling list).
 * Visible block-openers, invisible constructs, blank lines, and captions still
 * end the quote.
 */
class BlockQuoteListMarkerFoldsTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testBulletMarkerFoldsIntoQuotedParagraph(): void
    {
        $this->assertSame(
            "<blockquote><p>quoted\n- item</p></blockquote>\n",
            $this->converter->convert("> quoted\n- item"),
        );
    }

    public function testOrderedMarkerFoldsIntoQuotedParagraph(): void
    {
        $this->assertSame(
            "<blockquote><p>quoted\n1. item</p></blockquote>\n",
            $this->converter->convert("> quoted\n1. item"),
        );
    }

    public function testFoldMatchesTopLevelParagraphRule(): void
    {
        // At the top level a list marker folds into an open paragraph rather
        // than interrupting it; the quoted paragraph behaves identically.
        $this->assertSame(
            "<p>text\n- item</p>\n",
            $this->converter->convert("text\n- item"),
        );
    }

    public function testHeadingStillEndsTheQuote(): void
    {
        $this->assertSame(
            "<blockquote><p>quoted</p></blockquote>\n<section id=\"H\">\n  <h1>H</h1>\n</section>\n",
            $this->converter->convert("> quoted\n# H"),
        );
    }

    public function testBulletMarkerAfterQuotedHeadingEndsTheQuote(): void
    {
        // A heading inside the quote is a closed block: there is no open
        // paragraph for a bullet to fold into, so the list ends the quote and
        // becomes a top-level sibling (mirrors top-level `# h\n- item`).
        $this->assertSame(
            "<blockquote>\n  <h1 id=\"h\">h</h1>\n</blockquote>\n<ul>\n  <li>item</li>\n</ul>\n",
            $this->converter->convert("> # h\n- item"),
        );
    }

    public function testOrderedMarkerAfterQuotedHeadingEndsTheQuote(): void
    {
        $this->assertSame(
            "<blockquote>\n  <h1 id=\"h\">h</h1>\n</blockquote>\n<ol>\n  <li>item</li>\n</ol>\n",
            $this->converter->convert("> # h\n1. item"),
        );
    }

    public function testMarkerAfterParagraphThenQuotedHeadingEndsTheQuote(): void
    {
        // The heading clears the open-paragraph signal that the preceding
        // paragraph set, so the trailing list marker still ends the quote.
        $this->assertSame(
            "<blockquote>\n  <p>a</p>\n  <h1 id=\"h\">h</h1>\n</blockquote>\n<ul>\n  <li>item</li>\n</ul>\n",
            $this->converter->convert("> a\n> # h\n- item"),
        );
    }

    public function testReferenceDefinitionStillEndsTheQuote(): void
    {
        // An invisible construct (reference definition) ends the quote and is
        // consumed without rendering.
        $this->assertSame(
            "<blockquote><p>quoted</p></blockquote>\n",
            $this->converter->convert("> quoted\n[r]: /u"),
        );
    }

    public function testCommentStillEndsTheQuote(): void
    {
        $this->assertSame(
            "<blockquote>\n  <p>quoted</p>\n</blockquote>\n",
            $this->converter->convert("> quoted\n%% c"),
        );
    }

    public function testAttributeLineStillEndsTheQuote(): void
    {
        $this->assertSame(
            "<blockquote><p>quoted</p></blockquote>\n",
            $this->converter->convert("> quoted\n{.x}"),
        );
    }

    public function testCaptionStillAttaches(): void
    {
        $this->assertSame(
            "<figure>\n  <blockquote><p>quoted</p></blockquote>\n  <figcaption>cap</figcaption>\n</figure>\n",
            $this->converter->convert("> quoted\n^ cap"),
        );
    }

    public function testPrefixedListStaysARealListInTheQuote(): void
    {
        $this->assertSame(
            "<blockquote>\n  <ul>\n    <li>a</li>\n    <li>b</li>\n  </ul>\n</blockquote>\n",
            $this->converter->convert("> - a\n> - b"),
        );
    }

    public function testContinuationMarkerStillAttachesAList(): void
    {
        $this->assertSame(
            "<blockquote>\n  <p>q</p>\n  <ul>\n    <li>item</li>\n  </ul>\n</blockquote>\n",
            $this->converter->convert("> q\n+\n- item"),
        );
    }
}
