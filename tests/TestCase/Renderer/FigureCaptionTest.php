<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Figure caption placement in the non-HTML renderers: the caption sits on its
 * own line directly under the figure (`\n`); an image target used to glue it on
 * (`![a](/u)cap`). A blockquote target keeps the blank-line separation, and the
 * figure ends with a block separator so a following block is not glued. Matches
 * carve-js / carve-rs.
 */
class FigureCaptionTest extends TestCase
{
    protected CarveConverter $converter;

    protected MarkdownRenderer $markdown;

    protected PlainTextRenderer $plain;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
        $this->markdown = new MarkdownRenderer();
        $this->plain = new PlainTextRenderer();
    }

    private function md(string $src): string
    {
        return $this->markdown->render($this->converter->parse($src));
    }

    private function plain(string $src): string
    {
        return $this->plain->render($this->converter->parse($src));
    }

    public function testMarkdownImageCaptionOnItsOwnLine(): void
    {
        $this->assertSame("![a](/u)\ncap\n", $this->md("![a](/u)\n^ cap"));
    }

    public function testMarkdownCodeCaptionOnItsOwnLine(): void
    {
        $this->assertSame("```\nx\n```\ncap\n", $this->md("```\nx\n```\n^ cap"));
    }

    /**
     * PART 11 section 10c T1. This used to pin the BLANK LINE, and that spacing
     * was the defect: a caption on a quote is its ATTRIBUTION, and emitting it
     * as a sibling kept the words while losing what they mean - read back it was
     * attached to nothing, and a round trip produced a blockquote with no
     * attribution. It now stays inside the quote as a <footer>, the element this
     * target already reaches for when Markdown has no spelling for a construct.
     */
    public function testMarkdownBlockquoteAttributionStaysInsideTheQuote(): void
    {
        $this->assertSame("> q\n>\n> <footer>cap</footer>\n", $this->md("> q\n^ cap"));
    }

    public function testMarkdownFigureNotGluedToFollowingBlock(): void
    {
        $this->assertSame("![a](/u)\ncap\n\ntext\n", $this->md("![a](/u)\n^ cap\n\ntext"));
    }

    public function testPlainImageCaptionOnItsOwnLine(): void
    {
        $this->assertSame("a\ncap\n", $this->plain("![a](/u)\n^ cap"));
    }

    public function testPlainFigureNotGluedToFollowingBlock(): void
    {
        $this->assertSame("a\ncap\n\ntext\n", $this->plain("![a](/u)\n^ cap\n\ntext"));
    }
}
