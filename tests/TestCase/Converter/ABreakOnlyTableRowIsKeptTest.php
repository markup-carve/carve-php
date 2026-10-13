<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A cell holding only breaks writes as an empty cell, and a row of empty cells
 * is a row (PART 9 CARVE-P9-082), so the row is kept and only each break's
 * flattening is reported.
 */
class ABreakOnlyTableRowIsKeptTest extends TestCase
{
    /**
     * @return array<string, array{string, string, int}>
     */
    public static function blankCells(): array
    {
        return [
            'one break' => ['<td><br></td>', "| |\n", 1],
            'two breaks' => ['<td><br><br></td>', "| |\n", 2],
            'header break' => ['<th><br></th>', "|= |\n", 1],
        ];
    }

    #[DataProvider('blankCells')]
    public function testTheImporterKeepsTheRow(string $cell, string $carve, int $breaks): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $result = (new HtmlToCarve(importMode: $mode))
                ->convertWithReport('<table><tr>' . $cell . '</tr></table>');

            $this->assertSame($carve, $result->value, $mode);
            $this->assertCount($breaks, $result->diagnostics, $mode);
            foreach ($result->diagnostics as $diagnostic) {
                $this->assertSame('structure-unspellable', $diagnostic->code, $mode);
                $this->assertStringContainsString('/br[', (string)$diagnostic->path, $mode);
            }
        }
    }

    public function testCellAttributesKeepTheRowSpellable(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<table><tr><td id="x"><br></td></tr></table>');

        $this->assertSame("|{#x} |\n", $result->value);
        $this->assertNotContains('/table[1]/tr[1]', array_column($result->report()['diagnostics'], 'path'));
    }

    public function testABreakOnlyRowStaysAboveAContentRow(): void
    {
        $html = '<table><tr><td><br></td></tr><tr><td>a</td></tr></table>';
        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertSame("| |\n| a |\n", $result->value);
        $this->assertSame(['/table[1]/tr[1]/td[1]/br[1]'], array_column($result->report()['diagnostics'], 'path'));
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
