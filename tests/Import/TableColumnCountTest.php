<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-M-039 (carve#6738): `columns` counts the grid a parse counts (C2-B-172), with the slots row spans from above
 * take, and the alignment pass (C2-M-040) places the cells on the same grid (carve-js#5621).
 */
final class TableColumnCountTest extends TestCase
{
    /**
     * @return array<string, array{string, string, int}>
     */
    public static function tables(): array
    {
        return [
            'CC-2109 a column span beside a row span widens the table' => [
                '<table><tr><td rowspan="2">r</td></tr><tr><td colspan="2">w</td></tr></table>',
                "| r |\n| ^ | w | < |\n",
                3,
            ],
            'CC-2110 a cell beside a row span starts in the second column' => [
                '<table><tr><td rowspan="2">r</td></tr><tr><td>w</td></tr></table>',
                "| r |\n| ^ | w |\n",
                2,
            ],
            'each group counts its own row spans' => [
                '<table><thead><tr><th>h</th></tr></thead><tbody><tr><td rowspan="2">r</td></tr>'
                    . '<tr><td>w</td><td>x</td></tr></tbody></table>',
                "|= h |\n| r |\n| ^ | w | x |\n",
                3,
            ],
            'carve-js#5621 a short row beside a row span ends it' => [
                '<table><thead><tr><th>A</th><th>B</th><th align="right">C</th></tr></thead><tbody><tr><td>a</td>'
                    . '<td rowspan="2">r</td><td>x</td></tr><tr><td>b</td></tr><tr><td>c</td><td align="right">d</td>'
                    . '<td>y</td></tr></tbody></table>',
                "|= A |= B |={>} C |\n| a | r | x |\n| b | ^ |\n| c |{>} d | y |\n",
                3,
            ],
        ];
    }

    /**
     * @param string $html
     * @param string $source
     * @param int $columns
     */
    #[DataProvider('tables')]
    public function testImport(string $html, string $source, int $columns): void
    {
        $result = Carve::importHtml($html);
        $this->assertSame($source, $result->source);
        $this->assertSame([], $result->codes());
        $this->assertSame(array_fill(0, $columns, ['type' => 'table_column']), $result->document['children'][0]['columns']);
    }
}
