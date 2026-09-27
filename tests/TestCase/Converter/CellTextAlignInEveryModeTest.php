<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class CellTextAlignInEveryModeTest extends TestCase
{
    public function testCellTextAlignInEveryMode(): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $converter = new HtmlToCarve(importMode: $mode);
            foreach (
                [
                    ['<table><thead><tr><th style="text-align:left">V</th><th style="text-align:right">D</th></tr></thead><tbody><tr><td style="text-align:left">a</td><td style="text-align:right">b</td></tr></tbody></table>', "|=< V |=> D |\n| a | b |\n", 0, [['left', 'right'], [null, null]]],
                    ['<table><tr><th style="text-align:right">V</th></tr><tr><td style="text-align:center">a</td></tr><tr><td style="text-align:left">b</td></tr></table>', "|=> V |\n|~ a |\n|< b |\n", 0, [['right'], ['center'], ['left']]],
                    ['<table><tr><td style="text-align:justify;text-align:right">a</td><td style="text-align:right;text-align:left">b</td><td style="text-align:right !important">c</td><td style="text-align:center;text-align:justify">d</td></tr></table>', "|> a |< b | c |~ d |\n", 3, [['right', 'left', null, 'center']]],
                    ['<table><tr><td style="TEXT-ALIGN: CENTER; color:red">a</td><td style="text-align:justify">b</td></tr></table>', "|~ a | b |\n", 2, [['center', null]]],
                ] as [$html, $source, $count, $expected]
            ) {
                $result = $converter->convertWithReport($html);
                $this->assertSame($source, $result->value);
                $this->assertSame(array_fill(0, $count, 'style-unmapped'), array_column($result->report()['diagnostics'], 'code'));
                $ast = $converter->convertToAstWithReport($html);
                $this->assertSame(array_fill(0, $count, 'style-unmapped'), array_column($ast->report()['diagnostics'], 'code'));
                $actual = array_map(
                    static fn (array $row): array => array_map(static fn (array $cell): ?string => $cell['align'] ?? null, $row['cells']),
                    $ast->value['children'][0]['rows'],
                );
                $this->assertSame($expected, $actual);
            }
        }
    }
}
