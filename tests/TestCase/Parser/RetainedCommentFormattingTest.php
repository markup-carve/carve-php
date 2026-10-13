<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

class RetainedCommentFormattingTest extends TestCase
{
    public function testFormattingKeepsTheRetainedParagraph(): void
    {
        $source = "- ::: inner\n %% c\n text\n";
        $converter = new CarveConverter();
        $document = $converter->parse($source);
        $written = (new CarveRenderer())->render($document);
        self::assertSame($converter->convert($source), $converter->convert($written));
    }
}
