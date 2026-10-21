<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

class DefinitionBodyBlankContinuationTest extends TestCase
{
    public function testExpectedHtml(): void
    {
        $render = static fn (string $source): string => (new HtmlRenderer())->render((new BlockParser())->parse($source));

        self::assertSame("<ul>\n  <li>\n    <dl>\n      <dt>t</dt>\n    </dl>\n  </li>\n</ul>\n<p>:  head</p>\n<p>a</p>\n", $render("- :: t\n:  head\n\n     a"));
    }
}
