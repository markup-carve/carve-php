<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

final class ImageAltBracedCommentBracketTest extends TestCase
{
    public function testAnEarlyAltCloseDeclinesTheImageAndLeavesALink(): void
    {
        $converter = CarveConverter::create();
        self::assertSame('<p>!<a href="/u">ab</a></p>', $converter->convert("![a{% ] %}b](/u)\n"));
        self::assertSame('<p>!<a href="/u">ab</a></p>', $converter->convert("![a{% ] %}b][r]\n\n[r]: /u\n"));
    }

    public function testACommentWithoutABracketStillStaysInImageAlt(): void
    {
        self::assertSame(
            '<img src="/u" alt="a{% c %}b">',
            CarveConverter::create()->convert("![a{% c %}b](/u)\n"),
        );
    }

    public function testWriterEscapesAnAltBracketInsideABracedComment(): void
    {
        $document = new Document();
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Image('/u', 'a{% ] %}b'));
        $document->appendChild($paragraph);

        $source = (new CarveRenderer())->render($document);
        self::assertSame("![a{% \\] %}b](/u)\n", $source);
        self::assertSame('<img src="/u" alt="a{% ] %}b">', CarveConverter::create()->convert($source));
    }
}
