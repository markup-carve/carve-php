<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

final class MarkdownPipeDestinationRulingTest extends TestCase
{
    public function testLinkAndImageDestinationsEncodePipes(): void
    {
        $converter = new CarveConverter(renderer: new MarkdownRenderer());
        self::assertSame("[a](/x%7Cy)\n", $converter->convert("[a](/x|y)\n"));
        self::assertSame("![a](/x%7Cy)\n", $converter->convert("![a](/x|y)\n"));
    }

    public function testTableDestinationEncodesItsLiteralBackslashAndPipe(): void
    {
        $converter = new CarveConverter(renderer: new MarkdownRenderer());
        $markdown = $converter->convert("|= [a](/x\\\\\\|y) |= b |\n");
        self::assertStringContainsString('[a](/x%5C%7Cy)', $markdown);
    }

    public function testTableDestinationReadsAnEscapedPipeAsAPipe(): void
    {
        $converter = new CarveConverter(renderer: new MarkdownRenderer());
        $markdown = $converter->convert("|= [a](/x\\|y) |= b |\n");
        self::assertStringContainsString('[a](/x%7Cy)', $markdown);
    }
}
