<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class SourceLineTrackingTest extends TestCase
{
    public function testDisabledByDefaultEmitsNoSourceLineAttribute(): void
    {
        $converter = new CarveConverter();
        $html = $converter->convert("# Heading\n\nParagraph one.\n");

        $this->assertStringNotContainsString('data-source-line', $html);
    }

    public function testEnabledStampsTopLevelBlocksWithOneBasedSourceLine(): void
    {
        $converter = new CarveConverter(sourceLines: true);
        // 1-based lines: 1 "# Heading", 3 "Paragraph one.", 5 "Paragraph two."
        $html = $converter->convert("# Heading\n\nParagraph one.\n\nParagraph two.\n");

        $this->assertStringContainsString('data-source-line="1"', $html);
        $this->assertStringContainsString('data-source-line="3"', $html);
        $this->assertStringContainsString('data-source-line="5"', $html);
    }

    public function testEnabledStampsListAndBlockquote(): void
    {
        $converter = new CarveConverter(sourceLines: true);
        // 0 "Intro", 1 blank, 2 "- item a", 3 "- item b", 4 blank, 5 "> quote"
        $html = $converter->convert("Intro\n\n- item a\n- item b\n\n> quote\n");

        // The list block and the blockquote each carry a source line.
        $this->assertMatchesRegularExpression('/<ul[^>]*data-source-line="3"/', $html);
        $this->assertMatchesRegularExpression('/<blockquote[^>]*data-source-line="6"/', $html);
    }
}
