<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ABreakOnlyTableRowIsDroppedTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function blankCells(): array
    {
        return [
            'one break' => ['<td><br></td>'],
            'two breaks' => ['<td><br><br></td>'],
            'header break' => ['<th><br></th>'],
            'break and whitespace' => ["<td> \n<br> </td>"],
        ];
    }

    #[DataProvider('blankCells')]
    public function testTheImporterDropsAnUnspellableRow(string $cell): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $result = (new HtmlToCarve(importMode: $mode))
                ->convertWithReport('<table><tr>' . $cell . '</tr></table>');

            $this->assertSame("\n", $result->value, $mode);
            $this->assertCount(1, $result->diagnostics, $mode);
            $this->assertSame('structure-unspellable', $result->diagnostics[0]->code, $mode);
            $this->assertSame('/table[1]/tr[1]', $result->diagnostics[0]->path, $mode);
        }
    }

    public function testCellAttributesKeepTheRowSpellable(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<table><tr><td id="x"><br></td></tr></table>');

        $this->assertSame("|{#x} |\n", $result->value);
        $this->assertNotContains('/table[1]/tr[1]', array_column($result->report()['diagnostics'], 'path'));
    }

    public function testAContentRowSurvivesAfterABreakOnlyRow(): void
    {
        $html = '<table><tr><td><br></td></tr><tr><td>a</td></tr></table>';
        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertSame("| a |\n", $result->value);
        $this->assertSame(['/table[1]/tr[1]'], array_column($result->report()['diagnostics'], 'path'));
    }

    public function testABreakInARowWithContentStillReportsItsFlattening(): void
    {
        $html = '<table><tr><td><br></td><td>a</td></tr></table>';
        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertSame("| | a |\n", $result->value);
        $this->assertSame(
            ['/table[1]/tr[1]/td[1]/br[1]'],
            array_column($result->report()['diagnostics'], 'path'),
        );
    }
}
