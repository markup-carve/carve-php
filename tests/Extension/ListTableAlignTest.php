<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Extension;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Variants of the list-table clause cases CC-2455 to CC-2463 (C2-X-078, C2-B-211, C2-B-212; markup-carve/carve#6858).
 */
final class ListTableAlignTest extends TestCase
{
    /**
     * @var string
     */
    private const TABLE = "::: list-table\n- - a\n  - b\n:::\n";

    /**
     * @param string $source
     */
    private static function html(string $source): string
    {
        return Carve::toHtml($source, ['extensions' => ['carve.listTable'], 'renderers' => ['carve.listTable' => 'standard']]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidLists(): array
    {
        return [
            'aligns empty entry' => ['aligns="right,,center"'],
            'aligns trailing comma' => ['aligns="right,"'],
            'aligns valign word' => ['aligns=top'],
            'valigns baseline entry' => ['valigns=top,baseline'],
            'valigns upper case' => ['valigns=TOP'],
            'widths zero' => ['widths=0,50'],
            'widths above 100' => ['widths=50,101'],
            'widths leading dot' => ['widths=.5'],
            'widths exponent' => ['widths=1e1'],
        ];
    }

    #[DataProvider('invalidLists')]
    public function testAnInvalidColumnListSetsNothingAndIsNotWritten(string $attr): void
    {
        $this->assertSame(self::html(self::TABLE), self::html('{' . $attr . "}\n" . self::TABLE));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cellValues(): array
    {
        return [
            'tab around' => ["align=\"\tcenter\t\"", 'text-align: center;'],
            'mixed case baseline' => ['valign=BaseLine', 'vertical-align: baseline;'],
            'valign word on align' => ['align=top', 'text-align: right;'],
            'align word on valign' => ['valign=left', 'vertical-align: bottom;'],
            'empty align' => ['align=""', 'text-align: right;'],
        ];
    }

    #[DataProvider('cellValues')]
    public function testACellValueIsFoldedOrFallsBackToItsColumn(string $attr, string $style): void
    {
        $html = self::html("{aligns=right valigns=bottom}\n::: list-table\n- -{" . $attr . "} a\n:::\n");
        $this->assertStringContainsString($style, $html);
        $this->assertStringNotContainsString(' align=', $html);
        $this->assertStringNotContainsString('valign=', $html);
    }
}
