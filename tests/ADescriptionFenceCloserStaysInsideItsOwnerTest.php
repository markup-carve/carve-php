<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class ADescriptionFenceCloserStaysInsideItsOwnerTest extends TestCase
{
    public function testQuoteEndsTheCloserSearchAtTheDescriptionBodyColumn(): void
    {
        $source = ":: term\n: detail\n  ```\n  code\n> payload\n  ```\nafter\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString("<dd>detail\n<code>\ncode</code></dd>", $html);
        self::assertStringNotContainsString('<pre><code>', $html);
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
