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

    public function testStarItemReleasesTheFenceToTheBox(): void
    {
        $source = "- ::: box\n  * ::: |\n    ```\n    :::\n   ```\n   :::bad\n   :::\n- tail\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("<pre><code>:::bad\n:::\n</code></pre>", $html);
        self::assertStringContainsString('<li>tail</li>', $html);
    }

    public function testEmptyFenceBeforeTheBoxCloserIsStillABlock(): void
    {
        $source = "- ::: box\n  - ::: |\n    ```\n    :::\n   ```\n  :::\n- tail\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString('<pre><code></code></pre>', $html);
        self::assertStringContainsString('<li>tail</li>', $html);
    }

    public function testInfoAndTildeFencesOpenAfterTheNestedLiteral(): void
    {
        $source = "- ::: box\n  - ::: |\n    ```\n    :::\n   %s\n   :::bad\n   :::\n- tail\n";
        foreach (['``` php' => ' class="language-php"', '~~~' => ''] as $fence => $attribute) {
            $html = CarveConverter::create()->convert(sprintf($source, $fence));

            self::assertStringContainsString("<pre><code{$attribute}>:::bad\n:::\n</code></pre>", $html);
            self::assertStringContainsString('<li>tail</li>', $html);
        }
    }

    public function testAColonRunWithoutANestedLiteralStillClosesTheBox(): void
    {
        $source = "::: warning\n- item\n  :::\n```\n:::\nafter\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("<pre><code></code></pre>\n</aside>\n<p>after</p>", $html);
    }
}
