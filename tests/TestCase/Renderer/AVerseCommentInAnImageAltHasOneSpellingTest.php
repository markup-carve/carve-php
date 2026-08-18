<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

class AVerseCommentInAnImageAltHasOneSpellingTest extends TestCase
{
    public function testInlineAndReferenceImagesSpellTheEmptiedLineTheSameWay(): void
    {
        $converter = new CarveConverter();
        $inline = "::: |\n![a\n%% secret\nc](/u)\n:::\n";
        $reference = "::: |\n![a\n%% secret\nc][missing]\n:::\n";
        $writer = new CarveRenderer();

        $this->assertStringContainsString("![a\n%%\nc]", $writer->render($converter->parse($inline)));
        $this->assertStringContainsString("![a\n%%\nc]", $writer->render($converter->parse($reference)));
    }
}
