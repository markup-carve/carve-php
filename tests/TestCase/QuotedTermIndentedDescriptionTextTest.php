<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

class QuotedTermIndentedDescriptionTextTest extends TestCase
{
    public function testUnmarkedDescriptionTextStaysInTerm(): void
    {
        $render = static fn (string $source): string => (new HtmlRenderer())->render((new BlockParser())->parse($source));

        self::assertSame("<blockquote>\n  <ol>\n    <li>\n      <dl>\n        <dt>t\n: para</dt>\n      </dl>\n    </li>\n  </ol>\n</blockquote>\n", $render("> 1. :: t\n   : para"));
        self::assertSame("<blockquote>\n  <ul>\n    <li>\n      <dl>\n        <dt>term\n: definition</dt>\n      </dl>\n    </li>\n  </ul>\n</blockquote>\n", $render("> - :: term\n  : definition"));
        self::assertSame("<blockquote>\n  <ul>\n    <li>\n      <dl>\n        <dt>t</dt>\n      </dl>\n    </li>\n  </ul>\n</blockquote>\n<p>: d</p>\n", $render("> - :: t\n: d"));
    }
}
