<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

class BelowColumnCommentRetentionTest extends TestCase
{
    public function testExpectedHtml(): void
    {
        $render = static fn (string $source): string => (new HtmlRenderer())->render((new BlockParser())->parse($source));

        self::assertSame("<ul>\n  <li>\n    <ul>\n      <li>item\n        tail\n      </li>\n    </ul>\n  </li>\n</ul>\n", $render("- - item\n %%%\n  %%%\ntail"));
    }
}
