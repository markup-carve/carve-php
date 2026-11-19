<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Block\LineShapes;
use PHPUnit\Framework\TestCase;

final class FrontmatterFormatTest extends TestCase
{
    public function testC2B164FormatNameIsAsciiAlphanumericWithCase(): void
    {
        self::assertSame('JSON', LineShapes::frontmatter('---JSON', 0, 7));
        self::assertSame('1', LineShapes::frontmatter('---1', 0, 4));
        self::assertNull(LineShapes::frontmatter('---x-y', 0, 6));
    }
}
