<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-123, C2-W-034 (carve#6727): a region marker keeps the closer of the fence above it; an open fence before a
 * line comment reports a value that ends in a blank line.
 */
final class RegionMarkerFenceTest extends TestCase
{
    public function testFootnoteFenceKeepsItsCloserBeforeARegionMarker(): void
    {
        $input = "x[^n]\n\n[^n]: a\n\n  ```\n  x\n%% #endregion\n";
        $result = Carve::formatWithDiagnostics($input);

        self::assertSame("x[^n]\n\n[^n]: a\n\n  ```\n  x\n  ```\n\n%% #endregion\n", $result->source);
        self::assertSame([], $result->diagnostics);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function openFencesEndingInABlankLine(): array
    {
        return [
            'raw block, spaces' => [
                ['type' => 'raw_block', 'format' => 'html', 'value' => "x\n  \n"],
                "- a\n\n  ```=html\n  x\n    \n%% c\n",
            ],
            'comment block' => [
                ['type' => 'comment', 'form' => 'block', 'value' => "x\n\n"],
                "- a\n\n  %%%\n  x\n\n\n%% c\n",
            ],
        ];
    }

    /**
     * @param array<string, mixed> $block
     * @param string $expected
     *
     * @return void
     */
    #[DataProvider('openFencesEndingInABlankLine')]
    public function testOpenFenceReportsATrailingBlankLine(array $block, string $expected): void
    {
        $tree = [
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [
                    'type' => 'list',
                    'marker' => ['kind' => 'bullet', 'char' => '-'],
                    'spacing' => 'tight',
                    'children' => [
                        [
                            'type' => 'list_item',
                            'children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'a']]], $block],
                        ],
                    ],
                ],
                ['type' => 'comment', 'form' => 'line', 'value' => 'c'],
            ],
        ];
        $result = Carve::writeWithDiagnostics($tree);

        self::assertSame($expected, $result->source);
        self::assertSame(['field-unspellable'], $result->codes());
        self::assertSame('/children/0/children/0/children/1/value', $result->diagnostics[0]->path);
    }
}
