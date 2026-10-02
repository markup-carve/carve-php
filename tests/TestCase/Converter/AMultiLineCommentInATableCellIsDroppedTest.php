<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Converter\HtmlAstBuilder;
use MarkupCarve\Carve\Test\TestCase\ScalingGuardTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#2372: a pipe-table row is one line, so a comment holding
 * a line break has no spelling in a cell, and one that stands among the cell's
 * blocks is still written inline.
 */
class AMultiLineCommentInATableCellIsDroppedTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function dropped(): array
    {
        return [
            'inline' => ["<table><tr><td>a <!-- x\ny --> b</td></tr></table>", "| a  b |\n", '/table[1]/tr[1]/td[1]/comment()[2]'],
            'after a block' => ["<table><tr><td><p>a</p>\n\n<!-- note\n|x\n --></td></tr></table>", "| a |\n", '/table[1]/tr[1]/td[1]/comment()[3]'],
            'a closer among blocks' => ['<table><tr><td><p>a</p><!-- x %} y --><p>b</p></td></tr></table>', "| a b |\n", '/table[1]/tr[1]/td[1]/comment()[2]'],
        ];
    }

    #[DataProvider('dropped')]
    public function testTheCommentIsDroppedWithARow(string $html, string $carve, string $path): void
    {
        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertSame($carve, $result->value);
        $this->assertSame($carve, (new CarveConverter())->toCarve($carve));
        $dropped = array_values(array_filter(
            $result->report()['diagnostics'],
            static fn (array $row): bool => $row['code'] === 'element-dropped',
        ));
        $this->assertCount(1, $dropped);
        $this->assertSame($path, $dropped[0]['path']);
    }

    public function testAListTableCellKeepsItsComment(): void
    {
        $result = (new HtmlToCarve(listTableForBlockCells: true))
            ->convertWithReport("<table><tr><td><p>a</p><!-- x\ny --><p>b</p></td></tr></table>");

        $this->assertStringContainsString("%%%\n     x\n    y \n    %%%", $result->value);
        $this->assertSame([], $result->report()['diagnostics']);
    }

    public function testAOneLineCommentIsKeptInline(): void
    {
        $this->assertSame(
            "| a {%  one line  %} b |\n",
            (new HtmlToCarve())->convert('<table><tr><td>a <!-- one line --> b</td></tr></table>'),
        );
        $this->assertSame(
            "| a {%  one  %} b |\n",
            (new HtmlToCarve())->convert('<table><tr><td><p>a</p><!-- one --><p>b</p></td></tr></table>'),
        );
        $this->assertSame("a {%  x\ny  %} b\n", (new HtmlToCarve())->convert("<p>a <!-- x\ny --> b</p>"));
    }

    public function testRepeatedImportsKeepIndependentTableDecisions(): void
    {
        $importer = new HtmlToCarve(listTableForBlockCells: true, maxDiagnostics: 1);
        $pipe = "<table><tr><td>a<!-- x\ny -->b</td></tr><tr><td>c<!-- u\nv -->d</td></tr></table>";
        $first = $importer->convertWithReport($pipe);
        $this->assertCount(1, $first->report()['diagnostics']);
        $block = $importer->convertWithReport("<table><tr><td><p>a</p><!-- x\ny --><p>b</p></td></tr></table>");
        $this->assertSame([], $block->report()['diagnostics']);
        $this->assertSame($first->value, $importer->convertWithReport($pipe)->value);
        $this->assertSame($first->report(), $importer->convertWithReport($pipe)->report());
    }

    #[Group('scaling')]
    public function testMultilineCommentsDoNotRescanEveryOtherCell(): void
    {
        $builder = new HtmlAstBuilder(listTableForBlockCells: true);
        $row = "<tr><td>a<!-- x\ny -->b</td></tr>";
        $this->assertConversionScalesLinearly(
            static function (string $html) use ($builder): void {
                $builder->build($html);
            },
            '<table>' . str_repeat($row, 512) . '</table>',
            '<table>' . str_repeat($row, 2048) . '</table>',
            'table cells with multiline comments',
            512,
            2048,
        );
    }

}
