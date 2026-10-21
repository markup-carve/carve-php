<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CarveWriterNestedPlusParityTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function nestedShapes(): array
    {
        return [
            'nested literal plus' => [
                "- a\n  - b\n    - ```\n    +\ntail",
                "- a\n  - b\n    - ```\n      ```\n    \\+\n\ntail\n",
            ],
            'quote hosted literal plus' => [
                "> - a\n>   - b\n>     - ```\n>     +\n> tail",
                "> - a\n>   - b\n>     - ```\n>       ```\n>     \\+\n>\n> tail\n",
            ],
            'nested fence payload spacing' => [
                "- - ```\n\n\n  +\ntail\n",
                "- - ```\n\n    ```\n\n  \\+\n  tail\n",
            ],
            'stacked task box' => [
                "- > [x] a\n+\ntail",
                "- > [x] a\n+\ntail\n",
            ],
        ];
    }

    #[DataProvider('nestedShapes')]
    public function testWriterKeepsNestedPlusAndFenceLayout(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::toCarve($source));
    }
}
