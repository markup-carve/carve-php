<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AMarkdownThematicBreakKeepsItsTightListTest extends TestCase
{
    public function testThematicBreakAfterNestedListNeedsNoBlank(): void
    {
        $source = "- outer\n  - para\n\n  ---\n    ```\n";
        self::assertSame("- outer\n  - para\n  ---\n  ```\n  ```\n", CarveConverter::markdown()->convert($source));
    }
}
