<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 CARVE-P9-082: a standard row with an opening and a closing pipe and at
 * least one cell slot is a table row, whatever its cells hold.
 */
class AnExplicitlyDelimitedRowOfBlankCellsIsATableTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rowProvider(): array
    {
        return [
            'pipe-pair' => ["||\n", self::body('<td></td>')],
            'empty-body-w3' => ["|||\n", self::body('<td></td><td></td>')],
            'empty-body-w5' => ["|||||\n", self::body(str_repeat('<td></td>', 4))],
            'empty-ws-w2' => ["| |\n", self::body('<td></td>')],
            'empty-ws-w3' => ["| | |\n", self::body('<td></td><td></td>')],
            'empty-ws-w4' => ["| | | |\n", self::body(str_repeat('<td></td>', 3))],
            'empty-header' => ["|= |\n", "<table>\n  <thead>\n    <tr><th scope=\"col\"></th></tr>\n  </thead>\n</table>"],
            'empty-header-2' => ["|= |= |\n", "<table>\n  <thead>\n    <tr><th scope=\"col\"></th><th scope=\"col\"></th></tr>\n  </thead>\n</table>"],
            'interrupts-a-paragraph' => ["text\n| |\n", "<p>text</p>\n" . self::body('<td></td>')],
            'between-filled-rows' => [
                "| a |\n||\n| b |\n",
                "<table>\n  <tbody>\n    <tr><td>a</td></tr>\n    <tr><td></td></tr>\n    <tr><td>b</td></tr>\n  </tbody>\n</table>",
            ],
            'one-filled' => ["| a | |\n", self::body('<td>a</td><td></td>')],
            'attr-cell' => ["|{.x} |\n", self::body('<td class="x"></td>')],
            'align-cell' => ["|> |\n", self::body('<td style="text-align: right;"></td>')],
            'unmarked-align' => ["|>|\n", self::body('<td>&gt;</td>')],
            'tab-cell-single' => ["|\t|\n", self::body("<td>\t</td>")],
            'lone-pipe' => ["|\n", '<p>|</p>'],
            'no-closing-pipe' => ["| a | b\n", '<p>| a | b</p>'],
            'escaped-opening-pipe' => ["\\| |\n", '<p>| |</p>'],
        ];
    }

    #[DataProvider('rowProvider')]
    public function testADelimitedRowIsATableRowWhateverItsCellsHold(string $src, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($src), "\n"));
    }

    private static function body(string $cells): string
    {
        return "<table>\n  <tbody>\n    <tr>" . $cells . "</tr>\n  </tbody>\n</table>";
    }
}
