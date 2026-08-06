<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A block-position image takes the separator every other block takes.
 *
 * A lone image is a block node, and in the plain-text and terminal renderers it
 * was the one block that contributed no boundary at all: its alt text ran
 * straight into whatever followed. `![alt text](img.png)` above a paragraph
 * came out as `alt textfollowing paragraph` - one line, no break of any kind -
 * and an image above another image or a heading did the same.
 *
 * The asymmetry decides it without counting engines: this renderer already
 * separates every other pair of sibling blocks with a blank line, so the block
 * image was not making a different formatting choice, it was missing the case.
 * The Markdown renderer here had already fixed it (carve-php#633); these two
 * were left behind, which is what markup-carve/carve-rs#692 found while
 * auditing a snapshot that claimed three-way agreement.
 *
 * DECIDED BY POSITION, NOT BY CLASS. The renderers' match arms cover inline
 * nodes too, so a bare "is an image" test would append the separator to every
 * inline image and split `see ![a](x.png) here` across three lines.
 */
class BlockImageTakesTheBlockSeparatorTest extends TestCase
{
    protected function plain(string $source): string
    {
        return CarveConverter::plainText()->convert($source);
    }

    protected function ansi(string $source): string
    {
        return CarveConverter::ansi()->convert($source);
    }

    public function testAnImageAboveAParagraphIsSeparatedInPlainText(): void
    {
        $this->assertSame("alt text\n\nfollowing paragraph\n", $this->plain("![alt text](img.png)\n\nfollowing paragraph\n"));
    }

    public function testAnImageAboveAnotherImageIsSeparatedInPlainText(): void
    {
        $this->assertSame("a\n\nb\n", $this->plain("![a](i.png)\n\n![b](j.png)\n"));
    }

    public function testAnImageAboveAHeadingIsSeparatedInPlainText(): void
    {
        $this->assertSame("a\n\nH\n", $this->plain("![a](i.png)\n\n# H\n"));
    }

    public function testAnImageAboveAParagraphIsSeparatedInAnsi(): void
    {
        $this->assertStringContainsString("\n\nfollowing paragraph", $this->ansi("![alt text](img.png)\n\nfollowing paragraph\n"));
    }

    public function testAnInlineImageIsNotSeparated(): void
    {
        // The control the position test exists for: an image in running text is
        // inline, and appending a block separator here would split one sentence
        // across three lines.
        $this->assertSame("see a here\n", $this->plain("see ![a](x.png) here\n"));
    }

    public function testAnImageInsideAnInlineIsNotSeparated(): void
    {
        // The second control: the parent is an inline node rather than a
        // paragraph, which is the case a parent-is-a-paragraph test alone would
        // get wrong.
        $this->assertSame("a i b\n", $this->plain("a _![i](x.png)_ b\n"));
    }

    public function testTwoParagraphsAreUnchanged(): void
    {
        // The baseline this whole rule is measured against.
        $this->assertSame("first paragraph\n\nsecond paragraph\n", $this->plain("first paragraph\n\nsecond paragraph\n"));
    }
}
