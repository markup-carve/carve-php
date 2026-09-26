<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A blank line inside a fence nested in a description body must not read as a
 * line at column 0 when the writer decides whether to raise the nested list, so
 * `fmt` is a fixed point (markup-carve/carve-php#2516).
 */
class ABlankVerbatimLineDoesNotRaiseANestedDefinitionListTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sources(): array
    {
        return [
            'nested definition list' => [":: t\n: d\n\n  :: r\n  : q\n\n    ```\n\n    ```\n\n  x\n"],
            'nested list item' => [":: t\n: d\n\n  :: r\n  : q\n\n    - i\n      ```\n\n      ```\n\n  x\n"],
            'nested raw block' => [":: t\n: d\n\n  :: r\n  : q\n\n    ```=html\n\n    ```\n\n  x\n"],
        ];
    }

    #[DataProvider('sources')]
    public function testFmtIsAFixedPoint(string $source): void
    {
        $this->assertSame($source, CarveConverter::toCarve($source));
    }
}
