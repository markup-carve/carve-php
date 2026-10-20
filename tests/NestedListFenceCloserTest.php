<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NestedListFenceCloserTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function fenceShapes(): array
    {
        return [
            'backticks' => ["- a\n  - b\n    - ```\n      ```\n\ntail"],
            'tildes' => ["- a\n  - b\n    - ~~~\n      ~~~\n\ntail"],
            'info' => ["- a\n  - b\n    - ```lang\n      ```\n\ntail"],
            'payload' => ["- a\n  - b\n    - ```\n      +\n      ```\n\ntail"],
            'blank payload' => ["- a\n  - b\n    - ```\n\n      +\n      ```\n\ntail"],
            'deeper' => ["- a\n  - b\n    - c\n      - ```\n        ```\n\ntail"],
        ];
    }

    #[DataProvider('fenceShapes')]
    public function testNestedMarkerFenceCloserStaysWithItsOpener(string $source): void
    {
        $html = CarveConverter::create()->convert($source);
        self::assertSame(1, substr_count($html, '<pre><code'));
        self::assertSame(1, substr_count($html, '<code'));
        self::assertStringEndsWith("<p>tail</p>\n", $html);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function adjacentColumns(): array
    {
        return [
            'one column short' => [5, 2],
            'at content column' => [6, 1],
            'one column past' => [7, 1],
        ];
    }

    #[DataProvider('adjacentColumns')]
    public function testFenceShapedLineKeepsItsContainerAtAdjacentColumns(int $column, int $codeBlocks): void
    {
        $source = "- a\n  - b\n    - ```\n" . str_repeat(' ', $column) . "```\n\ntail";
        $html = CarveConverter::create()->convert($source);
        self::assertSame($codeBlocks, substr_count($html, '<pre><code'));
        self::assertSame($codeBlocks, substr_count($html, '<code'));
        if ($column === 7) {
            self::assertStringContainsString("<pre><code> ```\n\n</code></pre>", $html);
        }
        self::assertStringEndsWith("<p>tail</p>\n", $html);
    }

    public function testLongerMarkerFenceKeepsTheShorterRunAsPayload(): void
    {
        $source = "- a\n  - b\n    - ````\n      ```\n      ````\n\ntail";
        $html = CarveConverter::create()->convert($source);
        self::assertStringContainsString("<pre><code>```\n</code></pre>", $html);
        self::assertSame(1, substr_count($html, '<code'));
    }
}
