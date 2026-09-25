<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AListInATableCellLosesItsMarkersTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function lists(): array
    {
        return [
            'unordered' => ['ul', '| a b |' . "\n"],
            'ordered' => ['ol', '| a b |' . "\n"],
        ];
    }

    #[DataProvider('lists')]
    public function testOnlyItemContentEntersAnInlineCell(string $tag, string $expected): void
    {
        $html = '<table><tr><td><' . $tag . '><li>a</li><li>b</li></' . $tag . '></td></tr></table>';
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $result = (new HtmlToCarve(importMode: $mode))->convertWithReport($html);

            $this->assertSame($expected, $result->value, $mode);
            $this->assertSame(
                ['element-unwrapped', 'element-unwrapped', 'element-unwrapped'],
                array_column($result->report()['diagnostics'], 'code'),
                $mode,
            );
        }
    }

    public function testAdjacentCellTextKeepsWordBoundaries(): void
    {
        $html = '<table><tr><td>x<ul><li>a</li></ul>y</td></tr></table>';

        $this->assertSame("| x a y |\n", (new HtmlToCarve())->convert($html));
    }

    public function testEmptyItemsDoNotAddSpaces(): void
    {
        $html = '<table><tr><td><ul><li>a</li><li></li><li>b</li></ul></td></tr></table>';

        $this->assertSame("| a b |\n", (new HtmlToCarve())->convert($html));
    }
}
