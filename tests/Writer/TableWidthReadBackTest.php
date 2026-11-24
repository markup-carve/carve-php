<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5825: a column width is written as the shortest percentage that reads back as itself (C2-B-212), and one
 * that no percentage reads back as is reported (C2-W-117).
 */
final class TableWidthReadBackTest extends TestCase
{
    /**
     * @param string $text
     * @param bool $header
     *
     * @return array<string, mixed>
     */
    private static function cell(string $text, bool $header = false): array
    {
        return [

            'type' => 'table_cell',
            'header' => $header,
            'children' => [
                ['type' => 'cell_paragraph', 'children' => [['type' => 'text', 'value' => $text]]],
            ],
        ];
    }

    /**
     * @param array<array<string, mixed>> $groups
     * @param array<array<string, mixed>> $columns
     *
     * @return array<string, mixed>
     */
    private static function table(array $groups, array $columns = [['type' => 'table_column']]): array
    {
        return [

            'astVersion' => '2.0',
            'type' => 'document',
            'children' => [
                ['type' => 'table', 'columns' => $columns, 'children' => $groups],
            ],
        ];
    }

    /**
     * @param array<array<string, mixed>> $cells
     *
     * @return array<string, mixed>
     */
    private static function row(array $cells): array
    {
        return ['type' => 'table_row', 'children' => $cells];
    }

    /**
     * @param array<string, mixed> $tree
     *
     * @return array<array{0: string, 1: string|null}>
     */
    private static function losses(array $tree): array
    {
        return array_map(
            static fn ($d): array => [$d->code, $d->path],
            Carve::writeWithDiagnostics($tree)->diagnostics,
        );
    }

    public function testWidthIsWrittenToReadBackAsItself(): void
    {
        $tree = self::table(
            [['type' => 'table_body', 'children' => [self::row([self::cell('a')])]]],
            [['type' => 'table_column', 'width' => 0.333]],
        );

        $source = Carve::write($tree);
        self::assertSame("{widths=33.300000000000004}\n| a |\n", $source);
        $read = TreeEncoder::decodedWithOffsets(Carve::parse($source), $source);
        self::assertSame(0.333, $read['children'][0]['columns'][0]['width']);
    }

    /**
     * @return array<string, array{0: float, 1: string}>
     */
    public static function widthSpellings(): array
    {
        return [
            'tenth' => [0.1, '10'],
            'quarter' => [0.25, '25'],
            'one' => [1.0, '100'],
            'seventh' => [1 / 7, '14.285714285714285'],
            'small' => [0.001, '0.1'],
            'parsed 0.7' => [0.7 / 100, '0.7'],
            'tiny' => [1.0e-7, '0.000009999999999999999'],
        ];
    }

    #[DataProvider('widthSpellings')]
    public function testWidthTakesTheShortestSpellingThatReadsBack(float $width, string $entry): void
    {
        $tree = self::table(
            [['type' => 'table_body', 'children' => [self::row([self::cell('a')])]]],
            [['type' => 'table_column', 'width' => $width]],
        );

        $result = Carve::writeWithDiagnostics($tree);
        self::assertSame('{widths=' . $entry . "}\n| a |\n", $result->source);
        self::assertSame([], $result->diagnostics);
    }

    /**
     * @return array<string, array{0: float, 1: string}>
     */
    public static function unspellableWidths(): array
    {
        return [
            'third' => [1 / 3, '33.33333333333333'],
            'two thirds' => [2 / 3, '66.66666666666666'],
        ];
    }

    #[DataProvider('unspellableWidths')]
    public function testWidthWithNoSpellingKeepsItsNearestEntryAndIsReported(float $width, string $entry): void
    {
        $tree = self::table(
            [['type' => 'table_body', 'children' => [self::row([self::cell('a')])]]],
            [['type' => 'table_column', 'width' => $width]],
        );

        $result = Carve::writeWithDiagnostics($tree);
        self::assertSame('{widths=' . $entry . "}\n| a |\n", $result->source);
        self::assertSame([['field-unspellable', '/children/0/columns']], self::losses($tree));
    }

    public function testWidthsSweepReadsBackOrIsReported(): void
    {
        $widths = [];
        for ($i = 1; $i <= 1000; $i++) {
            $widths[] = (float)($i / 1000);
        }
        foreach ([3, 6, 7, 9, 11, 13] as $n) {
            for ($i = 1; $i < $n; $i++) {
                $widths[] = $i / $n;
            }
        }
        $children = [];
        foreach ($widths as $width) {
            $children[] = self::table(
                [['type' => 'table_body', 'children' => [self::row([self::cell('a')])]]],
                [['type' => 'table_column', 'width' => $width]],
            )['children'][0];
            $children[] = ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'p']]];
        }
        $result = Carve::writeWithDiagnostics(['astVersion' => '2.0', 'type' => 'document', 'children' => $children]);
        $reported = [];
        foreach ($result->diagnostics as $diagnostic) {
            $reported[(string)$diagnostic->path] = true;
        }
        $read = TreeEncoder::decodedWithOffsets(Carve::parse($result->source), $result->source);
        $kept = 0;
        foreach ($widths as $i => $width) {
            $back = $read['children'][2 * $i]['columns'][0]['width'] ?? null;
            if ($back !== null && (float)$back === $width) {
                $kept++;
                self::assertArrayNotHasKey('/children/' . 2 * $i . '/columns', $reported);

                continue;
            }
            // C2-W-117: no decimal percentage reads back as this width, so the nearest one is written and reported.
            self::assertArrayHasKey('/children/' . 2 * $i . '/columns', $reported, $width . ' read back as ' . var_export($back, true));
        }
        self::assertGreaterThan(800, $kept);
    }
}
