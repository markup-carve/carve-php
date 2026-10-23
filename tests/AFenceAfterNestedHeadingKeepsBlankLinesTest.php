<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AFenceAfterNestedHeadingKeepsBlankLinesTest extends TestCase
{
    public function testTheFenceAndNestedListUseTheStableLayout(): void
    {
        $source = "- a\n    - # h\n  ~~~\nafter\n";
        $expected = "- a\n\n  - # h\n\n  ```\n  ```\n\nafter\n";
        $formatted = CarveConverter::toCarve($source);
        $converter = new CarveConverter();

        self::assertSame($expected, $formatted);
        self::assertSame($converter->convert($source), $converter->convert($formatted));
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
    }
}
