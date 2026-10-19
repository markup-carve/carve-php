<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

class LosingFootnoteBodyReferenceTest extends TestCase
{
    public function testExpectedHtml(): void
    {
        $render = static fn (string $source): string => (new HtmlRenderer())->render((new BlockParser())->parse($source));

        self::assertSame("<ul>\n  <li>a</li>\n</ul>\n<p><a href=\"/url\">r</a></p>\n", $render("- a\n  [^f]: t\n[^f]: a\n     [r]: /url\n[r][]"));
    }
}
