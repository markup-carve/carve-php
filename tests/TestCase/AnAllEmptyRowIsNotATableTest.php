<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnAllEmptyRowIsNotATableTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rowProvider(): array
    {
        return [
            'empty-body-w2' => ["||\n", '<p>||</p>'],
            'empty-body-w3' => ["|||\n", '<p>|||</p>'],
            'empty-body-w4' => ["||||\n", '<p>||||</p>'],
            'empty-body-w5' => ["|||||\n", '<p>|||||</p>'],
            'empty-ws-w2' => ["| |\n", '<p>| |</p>'],
            'empty-ws-w3' => ["| | |\n", '<p>| | |</p>'],
            'empty-ws-w4' => ["| | | |\n", '<p>| | | |</p>'],
            'empty-header' => ["|= |\n", '<p>|= |</p>'],
            'empty-header-2' => ["|= |= |\n", '<p>|= |= |</p>'],
            'one-filled' => ["| a | |\n", "<table>\n  <tbody>\n    <tr><td>a</td><td></td></tr>\n  </tbody>\n</table>"],
            'filled-both' => ["|a|b|\n", "<table>\n  <tbody>\n    <tr><td>a</td><td>b</td></tr>\n  </tbody>\n</table>"],
            'attr-cell' => ["|{.x} |\n", "<table>\n  <tbody>\n    <tr><td class=\"x\"></td></tr>\n  </tbody>\n</table>"],
            'align-cell' => ["|> |\n", "<table>\n  <tbody>\n    <tr><td style=\"text-align: right;\"></td></tr>\n  </tbody>\n</table>"],
            'valign-cell' => ["|: |\n", "<table>\n  <tbody>\n    <tr><td>:</td></tr>\n  </tbody>\n</table>"],
            'header-filled' => ["|= a |\n", "<table>\n  <thead>\n    <tr><th scope=\"col\">a</th></tr>\n  </thead>\n</table>"],
            'single-empty' => ["||\n", '<p>||</p>'],
            'tab-cell-single' => ["|\t|\n", "<table>\n  <tbody>\n    <tr><td>\t</td></tr>\n  </tbody>\n</table>"],
            'tab-cells-multi' => ["|\t|\t|\n", "<table>\n  <tbody>\n    <tr><td>\t</td><td>\t</td></tr>\n  </tbody>\n</table>"],
        ];
    }

    #[DataProvider('rowProvider')]
    public function testAllEmptyRowsAreParagraphsAndSignificantCellsRemainTables(string $src, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($src), "\n"));
    }
}
