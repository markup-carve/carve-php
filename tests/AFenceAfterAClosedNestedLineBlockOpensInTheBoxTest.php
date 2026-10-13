<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AFenceAfterAClosedNestedLineBlockOpensInTheBoxTest extends TestCase
{
    public function testUnclosedFenceOwnsTheRemainingBoxContent(): void
    {
        $source = "- ::: box\n  - ::: |\n    ```\n    :::\n   ```\n   :::bad\n   :::\n- tail\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("<pre><code>:::bad\n:::\n</code></pre>", $html);
        self::assertStringContainsString('<li>tail</li>', $html);
    }

    public function testOpenItemParagraphKeepsTheRunInline(): void
    {
        $html = CarveConverter::create()->convert("- ::: box\n  - x\n   ```\n  :::\n");

        self::assertStringContainsString("<li>x\n<code></code></li>", $html);
    }

    public function testAColonRunWithoutANestedLiteralStillClosesTheBox(): void
    {
        $source = "::: warning\n- item\n  :::\n```\n:::\nafter\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("<pre><code></code></pre>\n</aside>\n<p>after</p>", $html);
    }
}
