<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `+` continuation row stays in the tree as `cell_wrap` markers and
 * `table_row.continuationLines` (CARVE-P12-069), so the writer can put the
 * `+` lines back. The expected shapes are carve-js's.
 */
class AContinuationLineIsAMarkerInTheJoinedCellTest extends TestCase
{
    /**
     * @return array<string, array{string, array<array{int, array<array<string>>}>}>
     */
    public static function provideTables(): array
    {
        return [
            'one join' => [
                "| a |\n+ b |\n",
                [[1, [['text:a', 'wrap:1/0', 'text:b']]]],
            ],
            'two lines into strong' => [
                "| a |\n+ *b |\n+ c* |\n",
                [[2, [['text:a', 'wrap:1/0', 'strong']]]],
            ],
            'inside a code span the join stays a space' => [
                "| a `b |\n+ c` |\n",
                [[1, [['text:a ', 'code']]]],
            ],
            'an empty continuation adds no marker' => [
                "| a | b |\n+ | |\n",
                [[1, [['text:a'], ['text:b']]]],
            ],
            'rowspan origin names the row below' => [
                "| a | b |\n| ^ | c |\n+ x | y |\n",
                [
                    [0, [['text:a', 'wrap:1/1', 'text:x'], ['text:b']]],
                    [1, [[], ['text:c', 'wrap:1/0', 'text:y']]],
                ],
            ],
            'colspan marker joins its origin' => [
                "| a | < |\n+ x | y |\n",
                [[1, [['text:a', 'wrap:1/0', 'text:x', 'wrap:1/0', 'text:y'], []]]],
            ],
        ];
    }

    /**
     * @param string $source
     * @param array<array{int, array<array<string>>}> $expected
     */
    #[DataProvider('provideTables')]
    public function testTheTreeKeepsTheMarkers(string $source, array $expected): void
    {
        $this->assertSame($expected, $this->rows($source));
    }

    /**
     * The written source reads back to the same tree. A join inside a code span
     * has no marker to write, so that cell comes back on one line.
     *
     * @param string $source
     * @param array<array{int, array<array<string>>}> $expected
     */
    #[DataProvider('provideTables')]
    public function testTheWriterPutsTheContinuationLinesBack(string $source, array $expected): void
    {
        $written = (new CarveConverter())->toCarve($source);

        $this->assertSame($expected, $this->rows($written));
        if (!str_contains($source, '`')) {
            $this->assertSame($source, $written);
        }
    }

    public function testAMarkerRendersAsOneSpace(): void
    {
        $html = (new CarveConverter())->convert("| a | < |\n+ x | y |\n");

        $this->assertStringContainsString('<td colspan="2">a x y</td>', $html);
    }

    public function testAHeaderRowKeepsItsLinesAboveTheSeparator(): void
    {
        $source = "| a |\n+ b |\n|---|\n| c |\n";

        $this->assertSame("|= a |\n+ b |\n| c |\n", (new CarveConverter())->toCarve($source));
    }

    /**
     * @param string $source
     *
     * @return array<array{int, array<array<string>>}>
     */
    private function rows(string $source): array
    {
        $ast = (new AstCodec())->encode((new CarveConverter())->parse($source));
        $rows = [];
        foreach ($ast['children'][0]['rows'] as $row) {
            $cells = [];
            foreach ($row['cells'] as $cell) {
                $cells[] = array_map(static fn (array $node): string => match ($node['type']) {
                    'text' => 'text:' . $node['value'],
                    'cell_wrap' => 'wrap:' . $node['line'] . '/' . ($node['row'] ?? 0),
                    default => $node['type'],
                }, $cell['children'] ?? []);
            }
            $rows[] = [$row['continuationLines'] ?? 0, $cells];
        }

        return $rows;
    }
}
