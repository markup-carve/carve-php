<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

class InvisibleLineListSeparationTest extends TestCase
{
    public function testExpectedHtml(): void
    {
        $render = static fn (string $source): string => (new HtmlRenderer())->render((new BlockParser())->parse($source));

        self::assertSame("<ul>\n  <li>\n    <ul>\n      <li>a</li>\n    </ul>\n  </li>\n  <li><p>b</p></li>\n</ul>\n", $render("- - a\n\n  %% just a note\n- b"));
        self::assertSame("<ul>\n  <li>\n    <ul>\n      <li>body</li>\n    </ul>\n    <p>prose</p>\n  </li>\n</ul>\n", $render("- - body\n\n  [^g]: n\n   prose"));
    }
}
