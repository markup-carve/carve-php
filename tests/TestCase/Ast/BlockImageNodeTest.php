<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\Image;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P12-043 publishes a block image as a paragraph with blockImage: true.
 */
class BlockImageNodeTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testALoneImageStaysInABlockImageParagraph(): void
    {
        $document = $this->converter->parse("![alt](img.png)\n");

        $paragraph = $document->getChildren()[0];
        $this->assertInstanceOf(Paragraph::class, $paragraph);
        $this->assertTrue($paragraph->isBlockImage());
        $this->assertInstanceOf(Image::class, $paragraph->getChildren()[0]);
    }

    public function testALoneImageWithAttributesStaysInABlockImageParagraph(): void
    {
        $document = $this->converter->parse("![alt](img.png){.x}\n");
        $paragraph = $document->getChildren()[0];

        $this->assertInstanceOf(Paragraph::class, $paragraph);
        $this->assertTrue($paragraph->isBlockImage());
        $this->assertTrue($paragraph->getChildren()[0]->hasClass('x'));
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

        $paragraph = $quote->getChildren()[0];
        $this->assertInstanceOf(Paragraph::class, $paragraph);
        $this->assertTrue($paragraph->isBlockImage());
        $this->assertInstanceOf(Image::class, $paragraph->getChildren()[0]);
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
