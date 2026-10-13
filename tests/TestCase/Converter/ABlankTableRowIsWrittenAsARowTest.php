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
 * A row of empty cells is a table row (PART 9 CARVE-P9-082): the writer spells it
 * (PART 11 CARVE-P11-014) and the HTML importer keeps it. Only a row with no cell
 * at all has no spelling.
 */
class ABlankTableRowIsWrittenAsARowTest extends TestCase
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
     * @return array<string, array{array<array<string, mixed>>, string}>
     */
    public static function blankRows(): array
    {
        return [
            'a header row' => [[self::row(['header' => true] + self::cell('')), self::row(self::cell('a'))], "|= |\n| a |\n"],
            'a data row' => [[self::row(self::cell('')), self::row(self::cell('a'))], "| |\n| a |\n"],
            'several columns' => [[self::row(self::cell(''), self::cell('')), self::row(self::cell('a'), self::cell('b'))], "| | |\n| a | b |\n"],
            'a row between two filled ones' => [[self::row(self::cell('a')), self::row(self::cell('')), self::row(self::cell('b'))], "| a |\n| |\n| b |\n"],
            'a row carrying attributes' => [[['attrs' => ['classes' => ['x']]] + self::row(self::cell('')), self::row(self::cell('a'))], "| |{.x}\n| a |\n"],
            'a table of only blank rows' => [[self::row(self::cell('')), self::row(self::cell(''))], "| |\n| |\n"],
        ];
    }

    /**
     * @param array<array<string, mixed>> $rows
     * @param string $carve
     */
    #[DataProvider('blankRows')]
    public function testTheWriterSpellsTheRow(array $rows, string $carve): void
    {
        $written = self::render($rows);
        $this->assertSame($carve, $written);
        $this->assertSame(
            (new CarveRenderer())->render((new AstCodec())->decode([
                'type' => 'document',
                'srcByteLength' => 0,
                'children' => [['type' => 'table', 'rows' => $rows]],
            ])),
            (new CarveRenderer())->render(CarveConverter::create()->parse($written)),
        );
    }

    public function testTheWriterRefusesARowWithNoCell(): void
    {
        try {
            self::render([self::row(), self::row(self::cell('a'))]);
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
     * @return array<string, array{string, string}>
     */
    public static function kept(): array
    {
        return [
            'a blank header row' => ['<table><thead><tr><th></th></tr></thead><tbody><tr><td>a</td></tr></tbody></table>', "|= |\n| a |\n"],
            'a blank data row' => ['<table><tr><td></td></tr><tr><td>a</td></tr></table>', "| |\n| a |\n"],
            'a blank row between two filled ones' => ['<table><tr><td>a</td></tr><tr><td></td></tr><tr><td>b</td></tr></table>', "| a |\n| |\n| b |\n"],
            'cells holding only whitespace' => ["<table><tr><td>  </td><td>\n\t</td></tr><tr><td>a</td><td>b</td></tr></table>", "| | |\n| a | b |\n"],
            'a table of only blank rows' => ['<p>x</p><table><tr><td></td></tr><tr><td></td></tr></table><p>y</p>', "x\n\n| |\n| |\n\ny\n"],
            'a captioned table of one blank row' => ['<table><caption>c</caption><tr><td></td></tr></table>', "| |\n^ c\n"],
            'a blank header row in the separator form' => [
                '<table data-carve-col-widths="3"><thead><tr><th></th></tr></thead><tbody><tr><td>a</td></tr></tbody></table>',
                "| |\n|---|\n| a |\n",
            ],
        ];
    }

    #[DataProvider('kept')]
    public function testTheImporterKeepsTheRow(string $html, string $carve): void
    {
        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertSame($carve, $result->value);
        $this->assertSame([], $result->diagnostics);
    }
}
