<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-M-040 (carve#6607): a column of a table with no head gives its alignment only while every column before it
 * gives one too, each axis on its own.
 */
final class HeadlessColumnAlignmentTest extends TestCase
{
    /**
     * @return array<string, array{string, string, array<string>}>
     */
    public static function tables(): array
    {
        return [
            'CC-2077 right second column after an unaligned first' => [
                '<table><tbody><tr><td>a</td><td style="text-align: right;">b</td></tr></tbody></table>',
                "| a |{>} b |\n",
                [],
            ],
            'CC-2078 each axis on its own' => [
                '<table><tr><td align="right">a</td><td align="right" valign="bottom">b</td></tr></table>',
                "{aligns=right,right}\n| a |{?v} b |\n",
                [],
            ],
            'the list stops at the first column that gives none' => [
                self::row('align="center"', '', 'align="right"'),
                "{aligns=center}\n| 0 | 1 |{>} 2 |\n",
                [],
            ],
            'valign list stops independently of align' => [
                self::row('valign="top"', 'align="left" valign="top"'),
                "{valigns=top,top}\n| 0 |{<} 1 |\n",
                [],
            ],
            'a baseline column gives none, so a later bottom column keeps its marks' => [
                self::row('valign="baseline"', 'valign="bottom"'),
                "| 0 |{?v} 1 |\n",
                ['field-unspellable'],
            ],
        ];
    }

    /**
     * @param string $html
     * @param string $source
     * @param array<string> $codes
     */
    #[DataProvider('tables')]
    public function testImport(string $html, string $source, array $codes): void
    {
        $result = Carve::importHtml($html);
        $this->assertSame($source, $result->source);
        $this->assertSame($codes, $result->codes());
    }

    private static function row(string ...$attrs): string
    {
        $cells = '';
        foreach ($attrs as $at => $attr) {
            $cells .= '<td' . ($attr === '' ? '' : ' ' . $attr) . '>' . $at . '</td>';
        }

        return '<table><tr>' . $cells . '</tr></table>';
    }
}
