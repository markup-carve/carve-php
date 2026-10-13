<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `<` glued to the opening pipe and ended by a space is an alignment run
 * (PART 9 §5 T11), so the cell is empty and left-aligned. Only a lone `<` with
 * no run is a colspan marker.
 */
class AnEmptyLeftAlignedCellIsNotAColspanTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideRows(): array
    {
        return [
            'glued after a cell' => [
                "| a |< |\n",
                '<tr><td>a</td><td style="text-align: left;"></td></tr>',
            ],
            'glued alone' => [
                "|< |\n",
                '<tr><td style="text-align: left;"></td></tr>',
            ],
            'glued with a continuation' => [
                "| a |< |\n+ b | c |\n",
                '<tr><td>a b</td><td style="text-align: left;">c</td></tr>',
            ],
            'bare marker stays a colspan' => [
                "| a |<|\n",
                '<tr><td colspan="2">a</td></tr>',
            ],
            'spaced marker stays a colspan' => [
                "| a | < |\n",
                '<tr><td colspan="2">a</td></tr>',
            ],
            'marker glued to the closing pipe stays a colspan' => [
                "| a | <|\n",
                '<tr><td colspan="2">a</td></tr>',
            ],
        ];
    }

    #[DataProvider('provideRows')]
    public function testRow(string $source, string $row): void
    {
        $html = (new CarveConverter())->convert($source);

        $this->assertStringContainsString($row, $html);
    }

    public function testTheWriterSpellingReadsBackAsWritten(): void
    {
        $this->assertSame("| a |< |\n", (new CarveConverter())->toCarve("| a |< |\n"));
    }
}
