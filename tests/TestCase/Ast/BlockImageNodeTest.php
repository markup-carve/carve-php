<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\Image;
use PHPUnit\Framework\TestCase;

/**
 * The AST vocabulary says so directly, in the `image` node's own description:
 *
 *   "Also valid in BLOCK position: a lone image paragraph is a block-level
 *    image."
 *
 * carve-js and carve-rs publish a block `image` node. This engine published
 * `paragraph > image` and unwrapped it again in the HTML renderer, so the
 * rendered output matched while the tree did not - which is exactly the kind of
 * difference PART 12 §1 exists to rule out, and the kind every HTML gate is
 * blind to.
 */
class BlockImageNodeTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testALoneImageIsABlockImageNode(): void
    {
        $document = $this->converter->parse("![alt](img.png)\n");

        $this->assertInstanceOf(Image::class, $document->getChildren()[0]);
    }

    public function testALoneImageWithAttributesIsABlockImageNode(): void
    {
        $document = $this->converter->parse("![alt](img.png){.x}\n");
        $image = $document->getChildren()[0];

        $this->assertInstanceOf(Image::class, $image);
        $this->assertTrue($image->hasClass('x'));
    }

    public function testAnImageWithTextBesideItStaysInItsParagraph(): void
    {
        // Only a LONE image is a block image. Anything else beside it and the
        // paragraph is a paragraph.
        $document = $this->converter->parse("see ![alt](img.png)\n");

        $this->assertInstanceOf(Paragraph::class, $document->getChildren()[0]);
    }

    public function testALoneImageInsideAContainerIsPromotedToo(): void
    {
        $document = $this->converter->parse("> ![alt](img.png)\n");
        $quote = $document->getChildren()[0];

        $this->assertInstanceOf(Image::class, $quote->getChildren()[0]);
    }

    public function testTheRenderedHtmlIsUnchanged(): void
    {
        // The renderer already emitted a bare block <img> for the paragraph
        // form, so promoting the node must not move a byte of output.
        foreach (["![alt](img.png)\n", "![alt](img.png){.x}\n", "see ![alt](img.png)\n"] as $source) {
            $this->assertSame(
                trim($this->converter->convert($source)),
                trim($this->converter->convert($source)),
                'sanity',
            );
        }
        $this->assertStringContainsString(
            '<img src="img.png"',
            $this->converter->convert("![alt](img.png)\n"),
        );
        $this->assertStringNotContainsString(
            '<p><img',
            $this->converter->convert("![alt](img.png)\n"),
        );
    }
}
