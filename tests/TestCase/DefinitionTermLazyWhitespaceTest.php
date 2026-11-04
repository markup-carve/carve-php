<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\TestCase;

class DefinitionTermLazyWhitespaceTest extends TestCase
{
    use RuleATodo;

    public function testFoldedLinesDropLeadingWhitespace(): void
    {
        $render = static fn (string $source): string => (new HtmlRenderer())->render((new BlockParser())->parse($source));

        self::assertSame("<blockquote>\n  <dl>\n    <dt>c</dt>\n  </dl>\n</blockquote>\n<section id=\"H\">\n  <h1>H</h1>\n</section>\n", $render("> :: c\n  # H"));
        self::assertSame("<pre><code></code></pre>\n<ul>\n  <li>\n    <blockquote>\n      <dl>\n        <dt>t</dt>\n      </dl>\n    </blockquote>\n  </li>\n</ul>\n<pre><code></code></pre>\n", $render("```\n```\n- > :: t\n```"));
    }
}
