<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AnEmptyUnclosedCodeBlockKeepsItsFencePairTest extends TestCase
{
    public function testBothSourceTargetsKeepTheEmptyBlock(): void
    {
        $source = "- outer\n  - para\n\n  ---\n    ```\n";
        $formatted = CarveConverter::toCarve($source);
        self::assertSame("- outer\n  - para\n  ---\n  ```\n  ```\n", $formatted);
        self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($formatted));
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
        self::assertStringContainsString("  ```\n  ```", CarveConverter::markdown()->convert($source));
    }
}
