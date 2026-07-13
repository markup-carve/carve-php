<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * The caption delimiter mirrors a heading's first line (§4/§553): `^` +
 * one-or-more literal SPACES (a space, not a tab) + non-empty content. `^ `
 * alone, `^\t…`, or a `^ ` whose content only appears on a later folded line is
 * NOT a caption, exactly as `# ` / `#\t…` is not a heading. Extra leading spaces
 * after the delimiter are folded into it.
 */
class CaptionWhitespaceTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testEmptyCaptionIsNotACaption(): void
    {
        $this->assertSame(
            "<p><img src=\"/u\" alt=\"a\">\n^</p>\n",
            $this->converter->convert("![a](/u)\n^ "),
        );
    }

    public function testCaptionWithContentOnlyOnLaterLineIsNotACaption(): void
    {
        $this->assertSame(
            "<p><img src=\"/u\" alt=\"a\">\n^ \nmore</p>\n",
            $this->converter->convert("![a](/u)\n^ \nmore"),
        );
    }

    public function testTabAfterCaretIsNotACaptionDelimiter(): void
    {
        $this->assertSame(
            "<p><img src=\"/u\" alt=\"a\">\n^\tx</p>\n",
            $this->converter->convert("![a](/u)\n^\tx"),
        );
    }

    public function testExtraLeadingSpacesFoldIntoTheDelimiter(): void
    {
        $this->assertSame(
            "<figure>\n  <img src=\"/u\" alt=\"a\">\n  <figcaption>x</figcaption>\n</figure>\n",
            $this->converter->convert("![a](/u)\n^  x"),
        );
    }

    public function testReferenceImageEmptyCaptionIsNotPromoted(): void
    {
        $this->assertSame(
            "<p><img src=\"/u\" alt=\"a\">\n^</p>\n",
            $this->converter->convert("![a][r]\n^ \n\n[r]: /u"),
        );
    }

    public function testReferenceImageCaptionOfInlineMarkupIsAFigure(): void
    {
        $this->assertSame(
            "<figure>\n  <img src=\"/u\" alt=\"a\">\n  <figcaption><strong>b</strong> c</figcaption>\n</figure>\n",
            $this->converter->convert("![a][r]\n^ *b* c\n\n[r]: /u"),
        );
    }

    public function testNonBreakingSpaceIsCaptionContent(): void
    {
        // A non-breaking space is content everywhere else in the parser, so
        // `^ \u{00a0}` IS a caption ("content" excludes only ASCII whitespace).
        $this->assertSame(
            "<figure>\n  <img src=\"/u\" alt=\"a\">\n  <figcaption>&nbsp;</figcaption>\n</figure>\n",
            $this->converter->convert("![a](/u)\n^ \u{00A0}"),
        );
    }
}
