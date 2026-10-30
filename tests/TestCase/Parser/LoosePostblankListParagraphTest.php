<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * The run under the item paragraph opens an unclosed fence (carve#4032), so the blank and the line below are its payload.
 */
final class LoosePostblankListParagraphTest extends TestCase
{
    public function testBlankAfterAnUnclosedFenceIsPayload(): void
    {
        $source = "- h\n  ```\n\n   a\n";
        $expected = "<ul>\n  <li>h\n    <pre><code>\n a\n</code></pre>\n  </li>\n</ul>";
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testNestedItemUnclosedFenceKeepsItsBlank(): void
    {
        $source = "- parent\n  - h\n    ```\n\n     a\n";
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString("<li>h\n        <pre><code>\n a\n</code></pre>", $html);
        self::assertStringContainsString('<li>parent', $html);
    }

    public function testDeeperLineAfterBlankIsFencePayload(): void
    {
        $source = "- h\n  ```\n\n    a\n";
        $expected = "<ul>\n  <li>h\n    <pre><code>\n  a\n</code></pre>\n  </li>\n</ul>";
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
