<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

class NestedInlineCodeKeepsLazyLineTest extends TestCase
{
    public function testFlushLineRemainsInsideOpenCodeSpan(): void
    {
        $render = static fn (string $source): string => (new HtmlRenderer())->render((new BlockParser())->parse($source));
        self::assertSame($render("- - a\n    ```` x\n    tail\n"), $render("- - a\n    ```` x\ntail\n"));
        self::assertStringContainsString("<code> x\ntail</code>", $render("- - a\n    ```` x\ntail\n"));
    }
}
