<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\SoftBreakMode;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §9b, CARVE-P11-061: inside a pipe-table cell the Markdown target
 * writes a `soft_break` as a single SPACE.
 *
 * A GFM row is one line, so the newline §9 would write ends the row and the rest
 * of the cell, plus every later cell, falls out of the table. Not `<br>`, which
 * §9a writes for the HARD break.
 *
 * A pipe row cannot spell a break inside a cell, so the plain-table case is an
 * interchange-only shape and arrives from an AST. A list-table cell body can
 * carry one in source (markup-carve/carve#2456).
 */
class MarkdownTableCellSoftBreakTest extends TestCase
{
    /**
     * @var string
     */
    private const HEADER = "| A | B |\n| --- | --- |\n";

    /**
     * @param array<string, mixed> $ast
     * @param \MarkupCarve\Carve\Renderer\SoftBreakMode|null $mode
     */
    private function renderAst(array $ast, ?SoftBreakMode $mode = null): string
    {
        $renderer = new MarkdownRenderer();
        if ($mode !== null) {
            $renderer->setSoftBreakMode($mode);
        }

        return $renderer->render((new AstCodec())->decode($ast));
    }

    /**
     * @param array<int, array<string, mixed>> $cells
     *
     * @return array<string, mixed>
     */
    private function tableAst(array $cells): array
    {
        $header = static fn (string $value): array => [
            'type' => 'table_cell',
            'header' => true,
            'children' => [['type' => 'text', 'value' => $value]],
        ];

        return [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'table',
                    'rows' => [
                        ['type' => 'table_row', 'cells' => [$header('A'), $header('B')]],
                        ['type' => 'table_row', 'cells' => $cells],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $children
     *
     * @return array<string, mixed>
     */
    private function cell(array $children): array
    {
        return ['type' => 'table_cell', 'header' => false, 'children' => $children];
    }

    public function testASoftBreakInACellIsOneSpace(): void
    {
        $ast = $this->tableAst([
            $this->cell([
                ['type' => 'text', 'value' => 'one'],
                ['type' => 'soft_break'],
                ['type' => 'text', 'value' => 'two'],
            ]),
            $this->cell([['type' => 'text', 'value' => 'B']]),
        ]);

        $this->assertSame(self::HEADER . "| one two | B |\n", $this->renderAst($ast));
    }

    /**
     * The reach is the cell, not the cell's direct children: a break nested in an
     * inline would end the row just the same.
     */
    public function testASoftBreakNestedInAnInlineIsOneSpace(): void
    {
        $ast = $this->tableAst([
            $this->cell([['type' => 'text', 'value' => 'A']]),
            $this->cell([
                [
                    'type' => 'strong',
                    'children' => [
                        ['type' => 'text', 'value' => 'x'],
                        ['type' => 'soft_break'],
                        ['type' => 'text', 'value' => 'y'],
                    ],
                ],
            ]),
        ]);

        $this->assertSame(self::HEADER . "| A | **x y** |\n", $this->renderAst($ast));
    }

    public function testAListTableCellReachesTheSameSeam(): void
    {
        $source = "{header-rows=1}\n::: list-table\n- - A\n  - B\n- - one\n    two\n  - x\n:::\n";

        $out = (new MarkdownRenderer())->render((new CarveConverter())->parse($source));

        $this->assertSame(self::HEADER . "| one two | x |\n", $out);
    }

    /**
     * Whatever the mode, no line inside the table ends in a newline the row did
     * not ask for. Stated this way the test survives a later respelling.
     */
    public function testNoModeWritesANewlineInACell(): void
    {
        $ast = $this->tableAst([
            $this->cell([
                ['type' => 'text', 'value' => 'one'],
                ['type' => 'soft_break'],
                ['type' => 'text', 'value' => 'two'],
            ]),
            $this->cell([['type' => 'text', 'value' => 'B']]),
        ]);

        foreach (SoftBreakMode::cases() as $mode) {
            $out = $this->renderAst($ast, $mode);
            $this->assertSame(3, count(array_filter(explode("\n", $out), 'strlen')), $mode->value . ': ' . var_export($out, true));
        }
    }

    public function testTheBreakModeDegradesToTheHardBreakSpelling(): void
    {
        $ast = $this->tableAst([
            $this->cell([
                ['type' => 'text', 'value' => 'one'],
                ['type' => 'soft_break'],
                ['type' => 'text', 'value' => 'two'],
            ]),
            $this->cell([['type' => 'text', 'value' => 'B']]),
        ]);

        $this->assertSame(self::HEADER . "| one<br>two | B |\n", $this->renderAst($ast, SoftBreakMode::Break));
    }

    public function testASoftBreakOutsideACellStaysANewline(): void
    {
        $out = (new MarkdownRenderer())->render((new CarveConverter())->parse("one\ntwo\n"));

        $this->assertSame("one\ntwo\n", $out);
    }
}
