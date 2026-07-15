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

    public function testEnabledStampsTopLevelBlocksWithZeroIndexedSourceLine(): void
    {
        $converter = new CarveConverter(sourceLines: true);
        // Lines: 0 "# Heading", 1 blank, 2 "Paragraph one.", 3 blank, 4 "Paragraph two."
        $html = $converter->convert("# Heading\n\nParagraph one.\n\nParagraph two.\n");

        $this->assertStringContainsString('data-source-line="0"', $html);
        $this->assertStringContainsString('data-source-line="2"', $html);
        $this->assertStringContainsString('data-source-line="4"', $html);
    }

    public function testEnabledStampsListAndBlockquote(): void
    {
        $converter = new CarveConverter(sourceLines: true);
        // 0 "Intro", 1 blank, 2 "- item a", 3 "- item b", 4 blank, 5 "> quote"
        $html = $converter->convert("Intro\n\n- item a\n- item b\n\n> quote\n");

        // The list block and the blockquote each carry a source line.
        $this->assertMatchesRegularExpression('/<ul[^>]*data-source-line="2"/', $html);
        $this->assertMatchesRegularExpression('/<blockquote[^>]*data-source-line="5"/', $html);
    }
}
