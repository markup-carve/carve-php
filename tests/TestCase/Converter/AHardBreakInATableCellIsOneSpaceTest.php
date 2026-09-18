<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

/**
 * A pipe cell is one line, so a hard break in one is written as one space, or
 * as nothing at the cell's edge (PART 11 §1b, markup-carve/carve#2067).
 */
class AHardBreakInATableCellIsOneSpaceTest extends TestCase
{
    /**
     * @var string
     */
    protected const HTML = '<table><tr><td>x<br>y</td></tr><tr><td>x<br></td></tr></table>';

    public function testTheImporterWritesOneSpace(): void
    {
        $this->assertSame("| x y |\n| x |\n", (new HtmlToCarve())->convert(self::HTML));
    }

    public function testTheImporterReportsEachBreak(): void
    {
        $rows = array_map(
            static fn ($diagnostic): array => [$diagnostic->code, $diagnostic->path, $diagnostic->severity],
            (new HtmlToCarve())->convertWithReport(self::HTML)->diagnostics,
        );

        $this->assertSame([
            ['structure-unspellable', '/table[1]/tr[1]/td[1]/br[2]', 'warning'],
            ['structure-unspellable', '/table[1]/tr[2]/td[1]/br[2]', 'warning'],
        ], $rows);
    }

    public function testTheAstExitKeepsTheBreak(): void
    {
        $this->assertSame(
            [['type' => 'text', 'value' => 'x'], ['type' => 'hard_break'], ['type' => 'text', 'value' => 'y']],
            $this->at((new HtmlToCarve())->convertToAst(self::HTML), 'children', 0, 'rows', 0, 'cells', 0, 'children'),
        );
    }

    public function testTheAstExitKeepsABreakAtTheCellEnd(): void
    {
        $this->assertSame(
            [['type' => 'text', 'value' => 'x'], ['type' => 'hard_break']],
            $this->at((new HtmlToCarve())->convertToAst(self::HTML), 'children', 0, 'rows', 1, 'cells', 0, 'children'),
        );
    }

    public function testTheAstExitLeavesAStandInCharacterInTheInputAlone(): void
    {
        $this->assertSame(
            [['type' => 'text', 'value' => "a\u{FDD0}b"]],
            $this->at((new HtmlToCarve())->convertToAst("<p>a\u{FDD0}b</p>" . self::HTML), 'children', 0, 'children'),
        );
    }

    public function testABreakOutsideACellStaysABreak(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<p>a<br>b</p>');

        $this->assertSame("a\\\nb\n", $result->value);
    }

    public function testABreakOutsideACellIsNotReported(): void
    {
        $this->assertSame([], (new HtmlToCarve())->convertWithReport('<p>a<br>b</p>')->diagnostics);
    }

    public function testAListTableCellKeepsItsBreak(): void
    {
        $html = '<table><tr><td><ul><li>a</li></ul></td><td>x<br>y</td></tr></table>';
        $result = (new HtmlToCarve(listTableForBlockCells: true))->convertWithReport($html);

        $this->assertStringContainsString("x\\\n", $result->value);
    }

    public function testAListTableCellBreakIsNotReported(): void
    {
        $html = '<table><tr><td><ul><li>a</li></ul></td><td>x<br>y</td></tr></table>';

        $this->assertSame([], (new HtmlToCarve(listTableForBlockCells: true))->convertWithReport($html)->diagnostics);
    }

    public function testTheWriterWritesOneSpaceBetweenTokens(): void
    {
        $this->assertSame("| x y |\n", $this->write([
            ['type' => 'text', 'value' => 'x'],
            ['type' => 'hard_break'],
            ['type' => 'text', 'value' => 'y'],
        ]));
    }

    /**
     * Only a DIRECT child at the cell edge writes nothing; a break at the edge
     * of an emphasis inside the cell keeps its space (markup-carve/carve#2067).
     */
    public function testTheWriterWritesNothingAtACellEdge(): void
    {
        $this->assertSame("| z{/w /} |\n", $this->write([
            ['type' => 'hard_break'],
            ['type' => 'text', 'value' => 'z'],
            ['type' => 'emphasis', 'children' => [['type' => 'text', 'value' => 'w'], ['type' => 'hard_break']]],
        ]));
    }

    public function testTheWriterKeepsABreakOutsideACell(): void
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'text', 'value' => 'a'],
                        ['type' => 'hard_break'],
                        ['type' => 'text', 'value' => 'b'],
                    ],
                ],
            ],
        ]);

        $this->assertSame("a\\\nb\n", (new CarveRenderer())->render($document));
    }

    /**
     * @param array<mixed> $tree
     * @param string|int ...$path
     *
     * @return mixed
     */
    protected function at(array $tree, string|int ...$path): mixed
    {
        $value = $tree;
        foreach ($path as $key) {
            $this->assertIsArray($value);
            $this->assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param array<int, array<string, mixed>> $cell
     */
    protected function write(array $cell): string
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'table',
                    'rows' => [
                        [
                            'type' => 'table_row',
                            'cells' => [
                                ['type' => 'table_cell', 'header' => false, 'children' => $cell],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        return (new CarveRenderer())->render($document);
    }
}
