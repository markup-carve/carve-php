<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class ADescriptionFenceCloserStaysInsideItsOwnerTest extends TestCase
{
    public function testQuoteEndsTheUnclosedFenceAtTheDescriptionBodyColumn(): void
    {
        $source = ":: term\n: detail\n  ```\n  code\n> payload\n  ```\nafter\n";
        $html = (new CarveConverter())->convert($source);

        // The unclosed fence ends with the description; the later run opens its own (carve#4032).
        self::assertStringContainsString("<dd>\n    <p>detail</p>\n    <pre><code>code\n</code></pre>\n  </dd>", $html);
        self::assertStringEndsWith("</blockquote>\n<pre><code>after\n</code></pre>", $html);
        self::assertStringContainsString('<blockquote>', $html);
    }

    public function testCloserInsideTheDescriptionStillOpensTheFence(): void
    {
        $source = ":: term\n: detail\n  ```\n  code\n  ```\n> payload\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString("<pre><code>code\n</code></pre>", $html);
        self::assertStringContainsString('<blockquote>', $html);
    }
}
