<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AFenceAfterAClosedNestedLineBlockOpensInTheBoxTest extends TestCase
{
    use RuleATodo;

    public function testUnclosedFenceOwnsTheRemainingBoxContent(): void
    {
        $source = "- ::: box\n  - ::: |\n    ```\n    :::\n   ```\n   :::bad\n   :::\n- tail\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("<pre><code>:::bad\n:::\n</code></pre>", $html);
        self::assertStringContainsString('<li>tail</li>', $html);
    }

    public function testOpenItemParagraphYieldsToTheFenceRun(): void
    {
        $html = CarveConverter::create()->convert("- ::: box\n  - x\n   ```\n  :::\n");

        self::assertStringContainsString('<li>x</li>', $html);
        self::assertStringContainsString("<pre><code>:::\n</code></pre>", $html);
    }

    public function testStarItemReleasesTheFenceToTheBox(): void
    {
        $source = "- ::: box\n  * ::: |\n    ```\n    :::\n   ```\n   :::bad\n   :::\n- tail\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("<pre><code>:::bad\n:::\n</code></pre>", $html);
        self::assertStringContainsString('<li>tail</li>', $html);
    }

    public function testTheBoxCloserInsideAnUnclosedFenceIsPayload(): void
    {
        $source = "- ::: box\n  - ::: |\n    ```\n    :::\n   ```\n  :::\n- tail\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("<pre><code>:::\n</code></pre>", $html);
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

    public function testAColonRunInsideAnUnclosedFenceIsPayload(): void
    {
        $source = "::: warning\n- item\n  :::\n```\n:::\nafter\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("<pre><code>:::\nafter\n</code></pre>\n</aside>", $html);
    }
}
