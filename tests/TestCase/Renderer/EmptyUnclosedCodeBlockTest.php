<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class EmptyUnclosedCodeBlockTest extends TestCase
{
    public function testEmptyBlockGetsAnExplicitClosingFence(): void
    {
        $source = "- outer\n  - para\n\n  ---\n    ```\n";
        $canonical = "- outer\n  - para\n  ---\n  ```\n  ```\n";

        self::assertSame($canonical, CarveConverter::toCarve($source));
        self::assertSame($canonical, CarveConverter::toCarve($canonical));
        self::assertSame(
            CarveConverter::create()->convert($source),
            CarveConverter::create()->convert($canonical),
        );
    }
}
