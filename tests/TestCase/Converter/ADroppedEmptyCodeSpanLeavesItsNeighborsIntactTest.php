<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An empty code span the importer drops writes nothing, so its neighbors are
 * spelled as if it were absent, and one in a cell before the last is dropped
 * (markup-carve/carve-php#2062, markup-carve/carve-php#2063).
 */
class ADroppedEmptyCodeSpanLeavesItsNeighborsIntactTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function htmlProvider(): array
    {
        return [
            'a strong after a dropped span after a word' => [
                '<p>x<code></code><strong>z</strong></p>',
                "x{*z*}\n",
                '<p>x<strong>z</strong></p>',
            ],
            'a strong before a dropped span before a word' => [
                '<p><strong>x</strong><code></code>y</p>',
                "{*x*}y\n",
                '<p><strong>x</strong>y</p>',
            ],
            'a span in a middle cell' => [
                '<table><tr><td>a</td><td>x<code></code></td><td>c</td></tr></table>',
                "| a | x | c |\n",
                '<table><tbody><tr><td>a</td><td>x</td><td>c</td></tr></tbody></table>',
            ],
            'a span ending a strong in a middle cell' => [
                '<table><tr><td>a</td><td><strong>x<code></code></strong></td><td>c</td></tr></table>',
                "| a | *x* | c |\n",
                '<table><tbody><tr><td>a</td><td><strong>x</strong></td><td>c</td></tr></tbody></table>',
            ],
            'a span in a cell before block content, in a pipe table' => [
                '<table><tr><td>x<code></code></td><td><p>b</p><p>c</p></td></tr></table>',
                "| x | b c |\n",
                '<table><tbody><tr><td>x</td><td>b c</td></tr></tbody></table>',
            ],
            'a span in a last cell that spans two columns' => [
                '<table><tr><td>a</td><td colspan="2">x<code></code></td></tr><tr><td>1</td><td>2</td><td>3</td></tr></table>',
                "| a | x | < |\n| 1 | 2 | 3 |\n",
                '<table><tbody><tr><td>a</td><td colspan="2">x</td></tr><tr><td>1</td><td>2</td><td>3</td></tr></tbody></table>',
            ],
            'a span in a last cell before a rowspan marker' => [
                '<table><tr><td>a</td><td>b</td><td rowspan="2">c</td></tr><tr><td>1</td><td>x<code></code></td></tr></table>',
                "| a | b | c |\n| 1 | x | ^ |\n",
                '<table><tbody><tr><td>a</td><td>b</td><td rowspan="2">c</td></tr><tr><td>1</td><td>x</td></tr></tbody></table>',
            ],
            'a span in a cell between two rowspan markers' => [
                '<table><tr><td rowspan="2">a</td><td>b</td><td rowspan="2">c</td></tr><tr><td>x<code></code></td></tr></table>',
                "| a | b | c |\n| ^ | x | ^ |\n",
                '<table><tbody><tr><td rowspan="2">a</td><td>b</td><td rowspan="2">c</td></tr><tr><td>x</td></tr></tbody></table>',
            ],
            'control: a span after a rowspan marker' => [
                '<table><tr><td rowspan="2">a</td><td>b</td></tr><tr><td>x<code></code></td></tr></table>',
                "| a | b |\n| ^ | x`` |\n",
                '<table><tbody><tr><td rowspan="2">a</td><td>b</td></tr><tr><td>x<code></code></td></tr></tbody></table>',
            ],
            'control: a span in the row after a rowspan ended' => [
                '<table><tr><td>a</td><td>b</td><td rowspan="2">c</td></tr><tr><td>1</td><td>2</td></tr><tr><td>3</td><td>x<code></code></td></tr></table>',
                "| a | b | c |\n| 1 | 2 | ^ |\n| 3 | x`` |\n",
                '<table><tbody><tr><td>a</td><td>b</td><td rowspan="2">c</td></tr><tr><td>1</td><td>2</td></tr><tr><td>3</td><td>x<code></code></td></tr></tbody></table>',
            ],
            'control: a span in the last cell' => [
                '<table><tr><td>a</td><td>c</td><td>x<code></code></td></tr></table>',
                "| a | c | x`` |\n",
                '<table><tbody><tr><td>a</td><td>c</td><td>x<code></code></td></tr></tbody></table>',
            ],
        ];
    }

    #[DataProvider('htmlProvider')]
    public function testTheImportIsWritten(string $html, string $carve, string $readBack): void
    {
        $this->assertSame($carve, (new HtmlToCarve())->convert($html));
    }

    #[DataProvider('htmlProvider')]
    public function testTheImportReadsBackAsTheHtml(string $html, string $carve, string $readBack): void
    {
        $rendered = CarveConverter::create()->convert((new HtmlToCarve())->convert($html));

        $this->assertSame($readBack, str_replace("\n", '', (string)preg_replace('/>\s+</', '><', $rendered)));
    }

    /**
     * Control: a list table writes each cell as its own block, so the run ends there.
     */
    public function testASpanInAListTableCellIsKept(): void
    {
        $html = '<table><tr><td>x<code></code></td><td><p>b</p><p>c</p></td></tr></table>';

        $this->assertSame(
            "::: list-table\n- - x``\n  - b\n\n    c\n:::\n",
            (new HtmlToCarve(listTableForBlockCells: true))->convert($html),
        );
    }
}
