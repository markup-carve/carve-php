<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

class NestedInlineCodeKeepsLazyLineTest extends TestCase
{
    public function testFlushLineLeavesTheUnclosedFence(): void
    {
        // The run is a fence opener (carve#4032); a flush line is not lazy fence payload.
        $render = static fn (string $source): string => (new HtmlRenderer())->render((new BlockParser())->parse($source));
        self::assertStringContainsString("<pre><code class=\"language-x\">tail\n</code></pre>", $render("- - a\n    ```` x\n    tail\n"));
        self::assertStringContainsString("<pre><code class=\"language-x\"></code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>", $render("- - a\n    ```` x\ntail\n"));
    }
}
