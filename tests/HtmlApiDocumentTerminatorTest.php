<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

final class HtmlApiDocumentTerminatorTest extends TestCase
{
    public function testHtmlApiOmitsOnlyTheDocumentTerminator(): void
    {
        $converter = new CarveConverter();
        self::assertSame('<p>x</p>', $converter->convert('x'));
        self::assertSame("<p>x</p>\n<p>y</p>", $converter->convert("x\n\ny"));
        self::assertSame('<p><strong>x</strong></p>', $converter->convert('*x*'));
    }

    public function testOtherRenderersKeepTheirOutput(): void
    {
        $converter = new CarveConverter(renderer: new MarkdownRenderer());
        self::assertSame("x\n", $converter->convert('x'));
    }
}
