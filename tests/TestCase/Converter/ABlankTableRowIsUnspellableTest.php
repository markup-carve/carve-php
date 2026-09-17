<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A table row whose every cell is blank is not a table row (markup-carve/carve#1954):
 * the writer refuses it and the HTML importer drops it (markup-carve/carve-js#1822).
 */
class ABlankTableRowIsUnspellableTest extends TestCase
{
    /**
     * @param string $value
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function cell(string $value, array $extra = []): array
    {
        return ['type' => 'table_cell', 'header' => false, 'children' => $value === '' ? [] : [['type' => 'text', 'value' => $value]]] + $extra;
    }

    /**
     * @param array<string, mixed> ...$cells
     *
     * @return array<string, mixed>
     */
    private static function row(array ...$cells): array
    {
        return ['type' => 'table_row', 'cells' => $cells];
    }

    /**
     * @param array<array<string, mixed>> $rows
     */
    private static function render(array $rows): string
    {
        return (new CarveRenderer())->render((new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [['type' => 'table', 'rows' => $rows]],
        ]));
    }

    /**
     * @return array<string, array{array<array<string, mixed>>}>
     */
    public static function blankRows(): array
    {
        return [
            'a header row' => [[self::row(['header' => true] + self::cell('')), self::row(self::cell('a'))]],
            'a data row' => [[self::row(self::cell('')), self::row(self::cell('a'))]],
            'several columns' => [[self::row(self::cell(''), self::cell('')), self::row(self::cell('a'), self::cell('b'))]],
            'a row between two filled ones' => [[self::row(self::cell('a')), self::row(self::cell('')), self::row(self::cell('b'))]],
            'a row carrying attributes' => [[['attrs' => ['classes' => ['x']]] + self::row(self::cell('')), self::row(self::cell('a'))]],
            'a table of only blank rows' => [[self::row(self::cell('')), self::row(self::cell(''))]],
        ];
    }

    /**
     * @param array<array<string, mixed>> $rows
     */
    #[DataProvider('blankRows')]
    public function testTheWriterRefusesTheRow(array $rows): void
    {
        try {
            self::render($rows);
            $this->fail('No SourceUnspellableException was thrown');
        } catch (SourceUnspellableException $exception) {
            $this->assertSame('table_row', $exception->nodeType);
        }
    }

    /**
     * @return array<string, array{array<array<string, mixed>>, string}>
     */
    public static function spelledRows(): array
    {
        return [
            'one filled cell' => [[self::row(self::cell('a'), self::cell(''))], "| a | |\n"],
            'a cell attribute' => [[self::row(self::cell('', ['attrs' => ['classes' => ['x']]])), self::row(self::cell('a'))], "|{.x} |\n| a |\n"],
            'an alignment marker' => [[self::row(self::cell('', ['align' => 'right'])), self::row(self::cell('a'))], "|> |\n| a |\n"],
            'a span marker' => [[self::row(self::cell('', ['span' => 'colspan'])), self::row(self::cell('a'))], "| < |\n| a |\n"],
        ];
    }

    /**
     * @param array<array<string, mixed>> $rows
     * @param string $carve
     */
    #[DataProvider('spelledRows')]
    public function testTheWriterWritesARowWithSomethingInIt(array $rows, string $carve): void
    {
        $this->assertSame($carve, self::render($rows));
    }

    /**
     * @return array<string, array{string, string, array<array{string, string}>}>
     */
    public static function imported(): array
    {
        $dropped = 'structure-unspellable';

        return [
            'a blank header row' => [
                '<table><thead><tr><th></th></tr></thead><tbody><tr><td>a</td></tr></tbody></table>',
                "| a |\n",
                [[$dropped, '/table[1]/tr[1]']],
            ],
            'a blank data row' => ['<table><tr><td></td></tr><tr><td>a</td></tr></table>', "| a |\n", [[$dropped, '/table[1]/tr[1]']]],
            'several columns' => [
                '<table><tr><td></td><td></td></tr><tr><td>a</td><td>b</td></tr></table>',
                "| a | b |\n",
                [[$dropped, '/table[1]/tr[1]']],
            ],
            'a blank row between two filled ones' => [
                '<table><tr><td>a</td></tr><tr><td></td></tr><tr><td>b</td></tr></table>',
                "| a |\n| b |\n",
                [[$dropped, '/table[1]/tr[2]']],
            ],
            'cells holding only whitespace' => [
                "<table><tr><td>  </td><td>\n\t</td></tr><tr><td>a</td><td>b</td></tr></table>",
                "| a | b |\n",
                [[$dropped, '/table[1]/tr[1]']],
            ],
            'a blank header row above a second header row' => [
                '<table><thead><tr><th></th></tr><tr><th>h</th></tr></thead><tbody><tr><td>a</td></tr></tbody></table>',
                "|= h |\n| a |\n",
                [[$dropped, '/table[1]/tr[1]']],
            ],
            'a table of only blank rows' => [
                '<p>x</p><table><tr><td></td></tr><tr><td></td></tr></table><p>y</p>',
                "x\n\ny\n",
                [[$dropped, '/table[2]/tr[1]'], [$dropped, '/table[2]/tr[2]']],
            ],
            'the caption of a table with no row left' => [
                '<table><caption>c</caption><tr><td></td></tr></table>',
                "\n",
                [[$dropped, '/table[1]/tr[1]'], ['element-dropped', '/table[1]/tr[1]']],
            ],
            'the caption goes with the last row dropped' => [
                '<table><caption>c</caption><tr><td></td></tr><tr><td></td></tr></table>',
                "\n",
                [[$dropped, '/table[1]/tr[1]'], [$dropped, '/table[1]/tr[2]'], ['element-dropped', '/table[1]/tr[2]']],
            ],
            'a blank header row in the separator form' => [
                '<table data-djot-col-widths="3"><thead><tr><th></th></tr></thead><tbody><tr><td>a</td></tr></tbody></table>',
                "| a |\n",
                [[$dropped, '/table[1]/tr[1]']],
            ],
            'the caption of a table with a row left' => [
                '<table><caption>c</caption><tr><td></td></tr><tr><td>a</td></tr></table>',
                "| a |\n^ c\n",
                [[$dropped, '/table[1]/tr[1]']],
            ],
        ];
    }

    /**
     * @param string $html
     * @param string $carve
     * @param array<array{string, string}> $rows
     */
    #[DataProvider('imported')]
    public function testTheImporterDropsTheRow(string $html, string $carve, array $rows): void
    {
        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertSame($carve, $result->value);
        $this->assertSame(
            array_map(static fn (array $row): array => [...$row, 'warning', 'dropped'], $rows),
            array_values(array_map(
                static fn ($diagnostic): array => [$diagnostic->code, $diagnostic->path, $diagnostic->severity, $diagnostic->fidelity()],
                $result->diagnostics,
            )),
        );
    }

    public function testTheImporterKeepsARowWithOneFilledCell(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<table><tr><td>a</td><td></td></tr></table>');

        $this->assertSame([], $result->diagnostics);
        $this->assertSame(
            "<table>\n  <tbody>\n    <tr><td>a</td><td></td></tr>\n  </tbody>\n</table>\n",
            CarveConverter::create()->convert($result->value),
        );
    }
}
