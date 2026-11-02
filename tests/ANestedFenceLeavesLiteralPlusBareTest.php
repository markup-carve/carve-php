<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class ANestedFenceLeavesLiteralPlusBareTest extends TestCase
{
    use RuleATodo;

    public function testPlainCodeAndRawFencesLeaveThePlusBare(): void
    {
        $cases = [
            ["- - - ```\n    +\ntail\n", "- - - ```\n      ```\n    \\+\n    tail\n"],
            ["- - - ```=html\n      x\n    +\ntail\n", "- - - ```=html\n      x\n      ```\n    \\+\n    tail\n"],
            ["- - - - ```\n        x\n      +\ntail\n", "- - - - ```\n        x\n        ```\n      \\+\n      tail\n"],
        ];
        $converter = new CarveConverter();
        foreach ($cases as [$source, $expected]) {
            $formatted = CarveConverter::toCarve($source);
            self::assertSame($expected, $formatted);
            self::assertSame($converter->convert($source), $converter->convert($formatted));
            self::assertSame($formatted, CarveConverter::toCarve($formatted));
        }
    }

    public function testTaskFenceEscapesThePlusAtTheItemContentColumn(): void
    {
        $source = "- - [ ] ```js\n        x\n\n  +\ntail\n";
        $expected = "- - [ ] ```js\n        x\n\n    ```\n\n  \\+\n  tail\n";
        $formatted = CarveConverter::toCarve($source);
        $converter = new CarveConverter();

        self::assertSame($expected, $formatted);
        self::assertSame($converter->convert($source), $converter->convert($formatted));
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
    }
}
