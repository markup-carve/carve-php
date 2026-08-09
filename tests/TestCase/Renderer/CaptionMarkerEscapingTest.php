<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

class CaptionMarkerEscapingTest extends TestCase
{
    public function testAnOrphanCaretStaysBareInBothWriterPasses(): void
    {
        $source = "^ cap \n # head\n";

        $this->assertSame("^ cap\n\\# head\n", CarveConverter::carve()->convert($source));
    }

    public function testATabAfterTheCaretIsNotACaptionSlot(): void
    {
        $source = "![Moon](m.jpg)\n^\tFigure 1\n";

        $this->assertSame($source, CarveConverter::carve()->convert($source));
    }

    public function testSoftBreakInsideFollowingParagraphDoesNotInheritCaptionSlot(): void
    {
        $source = "| a | b |\n\npara\n^ cap\n";

        $this->assertSame($source, CarveConverter::carve()->convert($source));
    }

    public function testIngestedImageLineStillProtectsFollowingCaret(): void
    {
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Image('/u', 'a'));
        $paragraph->appendChild(new SoftBreak());
        $paragraph->appendChild(new EscapedText('^'));
        $paragraph->appendChild(new Text(' cap'));
        $document = new Document();
        $document->appendChild($paragraph);

        $this->assertSame("![a](/u)\n\\^ cap\n", (new CarveRenderer())->render($document));
    }

    public function testAnImageOnALaterParagraphLineDoesNotCreateACaptionSlot(): void
    {
        $source = ":name:\n![a](/u)\n^ cap\n\\\"\n";

        $this->assertSame($source, CarveConverter::carve()->convert($source));
    }
}
