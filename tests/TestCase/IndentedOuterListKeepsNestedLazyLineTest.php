<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

class IndentedOuterListKeepsNestedLazyLineTest extends TestCase
{
    public function testFlushLineStaysInDeepestOpenParagraph(): void
    {
        $render = static fn (string $source): string => (new HtmlRenderer())->render((new BlockParser())->parse($source));
        $source = "  - a\n    - > - - x\nflush\n";
        self::assertStringContainsString("<li>x\nflush</li>", $render($source));
        self::assertStringNotContainsString('<p>flush</p>', $render($source));
        self::assertStringContainsString("<li>x\nflush</li>", $render("- > - - x\nflush\n"));
    }
}
