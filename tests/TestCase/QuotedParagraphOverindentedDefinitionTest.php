<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

class QuotedParagraphOverindentedDefinitionTest extends TestCase
{
    public function testExpectedHtml(): void
    {
        $render = static fn (string $source): string => (new HtmlRenderer())->render((new BlockParser())->parse($source));

        self::assertSame("<ul>\n  <li>\n    <blockquote>\n      <dl>\n        <dt>t</dt>\n      </dl>\n    </blockquote>\n  </li>\n</ul>\n<blockquote><p>: d\n[^f]: n\ntail</p></blockquote>\n", $render("- > :: t\n> : d\n>   [^f]: n\ntail"));
    }
}
