<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnUnmappedStyleDeclarationIsNamedTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: list<array{0: string, 1: string}>}>
     */
    public static function declarationProvider(): array
    {
        return [
            'one that maps' => ['<td style="text-align:right">a</td>', []],
            'one that does not' => [
                '<td style="text-align:justify">a</td>',
                [['CSS declaration text-align was not mapped', '/table[1]/tr[1]/td[1]']],
            ],
            'a value no reader honors' => [
                '<td style="text-align:right !important">a</td>',
                [['CSS declaration text-align was not mapped', '/table[1]/tr[1]/td[1]']],
            ],
            'two different properties' => [
                '<td style="color:red;font-weight:bold">a</td>',
                [
                    ['CSS declaration color was not mapped', '/table[1]/tr[1]/td[1]'],
                    ['CSS declaration font-weight was not mapped', '/table[1]/tr[1]/td[1]'],
                ],
            ],
            'a mapped one beside an unmapped one' => [
                '<td style="text-align:center;color:red">a</td>',
                [['CSS declaration color was not mapped', '/table[1]/tr[1]/td[1]']],
            ],
        ];
    }

    /**
     * @param string $cell
     * @param list<array{0: string, 1: string}> $expected
     */
    #[DataProvider('declarationProvider')]
    public function testEachUnmappedDeclarationGetsItsOwnRow(string $cell, array $expected): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<table><tr>' . $cell . '</tr></table>');

        $rows = [];
        foreach ($result->diagnostics as $diagnostic) {
            $row = $diagnostic->toArray();
            if ($row['code'] === 'style-unmapped') {
                $rows[] = [$row['message'], $row['path']];
            }
        }

        $this->assertSame($expected, $rows);
    }
}
