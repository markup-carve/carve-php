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

    /**
     * The caption slot is a SPACE after the marker. A tab leaves the line as
     * prose - corpus
     * `231-a-tab-after-a-heading-quote-or-caption-marker-leaves-the-line-as-prose-2`
     * is exactly this document - so the caret re-parses as text either way and
     * escaping it only changes the bytes.
     *
     * Offering the slot after an INLINE image made the difference observable:
     * this writer emitted a backslash here where carve-js emits the bare caret.
     * The corpus case has no `.fmt` fixture, so only the cross-engine render
     * comparison could see it.
     */
    public function testATabAfterTheCaretIsNotACaptionSlot(): void
    {
        $source = "![Moon](m.jpg)\n^\tFigure 1\n";

        $this->assertSame($source, CarveConverter::carve()->convert($source));
    }

    public function testAnImageOnALaterParagraphLineDoesNotCreateACaptionSlot(): void
    {
        $source = ":name:\n![a](/u)\n^ cap\n\\\"\n";

        $this->assertSame($source, CarveConverter::carve()->convert($source));
    }
}
