<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

class AuthoredFenceIndentAfterListLeadTest extends TestCase
{
    public function testPayloadLeftOfAuthoredBaseLosesTheExcessColumn(): void
    {
        $source = "- a\n    ```\n   x\n  ```\n";
        $html = (new HtmlRenderer())->render((new BlockParser())->parse($source));
        self::assertSame("<ul>\n  <li>a\n    <pre><code>x\n</code></pre>\n  </li>\n</ul>\n", $html);
    }

    public function testFootnoteMarkerDoesNotCountAsRemovedIndent(): void
    {
        $source = "ref[^n]\n\n[^n]: ```\n       x\n      ```\n";
        $html = (new HtmlRenderer())->render((new BlockParser())->parse($source));
        self::assertStringContainsString("<pre><code>     x\n    ```\n</code></pre>", $html);
    }
}
