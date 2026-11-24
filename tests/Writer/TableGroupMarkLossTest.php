<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Writer\CarveWriteError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5824: the attributes of a table group and the fields of a span mark that a pipe table cannot spell are
 * reported (C2-W-117, C2-W-134), and a span mark is written bare (C2-W-063).
 */
final class TableGroupMarkLossTest extends TestCase
{
    /**
     * @param string $text
     * @param bool $header
     *
     * @return array<string, mixed>
     */
    private static function cell(string $text, bool $header = false): array
    {
        return [

            'type' => 'table_cell',
            'header' => $header,
            'children' => [
                ['type' => 'cell_paragraph', 'children' => [['type' => 'text', 'value' => $text]]],
            ],
        ];
    }

    /**
     * @param array<array<string, mixed>> $groups
     * @param array<array<string, mixed>> $columns
     *
     * @return array<string, mixed>
     */
    private static function table(array $groups, array $columns = [['type' => 'table_column']]): array
    {
        return [

            'astVersion' => '2.0',
            'type' => 'document',
            'children' => [
                ['type' => 'table', 'columns' => $columns, 'children' => $groups],
            ],
        ];
    }

    /**
     * @param array<array<string, mixed>> $cells
     *
     * @return array<string, mixed>
     */
    private static function row(array $cells): array
    {
        return ['type' => 'table_row', 'children' => $cells];
    }

    /**
     * @param array<string, mixed> $tree
     *
     * @return array<array{0: string, 1: string|null}>
     */
    private static function losses(array $tree): array
    {
        return array_map(
            static fn ($d): array => [$d->code, $d->path],
            Carve::writeWithDiagnostics($tree)->diagnostics,
        );
    }

    public function testHeadAttributesAreReported(): void
    {
        $tree = self::table([
            ['type' => 'table_head', 'attrs' => ['id' => 'head'], 'children' => [self::row([self::cell('A', true)])]],
            ['type' => 'table_body', 'children' => [self::row([self::cell('x')])]],
        ]);

        $result = Carve::writeWithDiagnostics($tree);
        self::assertSame("|= A |\n| x |\n", $result->source);
        self::assertSame([['field-unspellable', '/children/0/children/0/attrs']], self::losses($tree));

        $this->expectException(CarveWriteError::class);
        Carve::write($tree);
    }

    public function testBodyAndFootAttributesAreReported(): void
    {
        $tree = self::table([
            ['type' => 'table_body', 'attrs' => ['classes' => ['b']], 'children' => [self::row([self::cell('x')])]],
            ['type' => 'table_foot', 'attrs' => ['id' => 'f'], 'children' => [self::row([self::cell('z')])]],
        ]);

        self::assertSame("{footer-rows=1}\n| x |\n| z |\n", Carve::writeWithDiagnostics($tree)->source);
        self::assertSame([
            ['field-unspellable', '/children/0/children/0/attrs'],
            ['field-unspellable', '/children/0/children/1/attrs'],
        ], self::losses($tree));
    }

    public function testGroupAttributesKeepATableOutOfTheDelimitedForm(): void
    {
        // A line feed in a cell asks for the delimited form, which has no spelling of group attributes either.
        $tree = self::table([
            ['type' => 'table_body', 'attrs' => ['id' => 'b'], 'children' => [self::row([self::cell("a\nb")])]],
        ]);

        self::assertSame("| a b |\n", Carve::writeWithDiagnostics($tree)->source);
        self::assertContains(['field-unspellable', '/children/0/children/0/attrs'], self::losses($tree));
    }

    public function testGroupsOfATableWithoutRowsAreReported(): void
    {
        $tree = self::table([
            ['type' => 'table_head', 'attrs' => ['id' => 'h'], 'children' => []],
            ['type' => 'table_body', 'attrs' => ['id' => 'b'], 'children' => []],
        ], []);

        self::assertSame("{table body-rows=0}\n```csv\n```\n", Carve::writeWithDiagnostics($tree)->source);
        self::assertSame([
            ['structure-unspellable', '/children/0/children/0'],
            ['field-unspellable', '/children/0/children/1/attrs'],
        ], self::losses($tree));
    }

    public function testHeaderOnABodyMarkIsReported(): void
    {
        $origin = self::cell('B', true) + ['rowspan' => 2];
        $mark = ['type' => 'table_cell', 'header' => true, 'span' => 'rowspan', 'children' => []];
        $tree = self::table([
            [

                'type' => 'table_body',
                'children' => [
                    self::row([$origin, self::cell('2')]),
                    self::row([$mark, self::cell('3')]),
                ],
            ],
        ], [['type' => 'table_column'], ['type' => 'table_column']]);

        self::assertSame("|= B | 2 |\n| ^ | 3 |\n", Carve::writeWithDiagnostics($tree)->source);
        self::assertSame(
            [['field-unspellable', '/children/0/children/0/children/1/children/0/header']],
            self::losses($tree),
        );
    }

    public function testAHeadMarkWithHeaderFalseIsNoLoss(): void
    {
        $source = "|= a |= < |\n| b | c |\n";
        $tree = TreeEncoder::decodedWithOffsets(Carve::parse($source), $source);

        $result = Carve::writeWithDiagnostics($tree);
        self::assertSame($source, $result->source);
        self::assertSame([], $result->diagnostics);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<array{0: string, 1: string}>}>
     */
    public static function markFields(): array
    {
        $at = '/children/0/children/0/children/0/children/1';

        return [
            'attrs' => [['attrs' => ['id' => 'm']], [['field-unspellable', $at . '/attrs']]],
            'align' => [['align' => 'left'], [['field-unspellable', $at . '/align']]],
            'header before align' => [['header' => true, 'align' => 'left'], [['field-unspellable', $at . '/header']]],
            'children' => [
                ['children' => [['type' => 'cell_paragraph', 'children' => [['type' => 'text', 'value' => 'x']]]]],
                [['structure-unspellable', $at . '/children/0']],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<array{0: string, 1: string}> $expected
     */
    #[DataProvider('markFields')]
    public function testAMarkIsWrittenBareAndItsOtherFieldsAreReported(array $fields, array $expected): void
    {
        $origin = self::cell('A') + ['colspan' => 2];
        $mark = array_merge(['type' => 'table_cell', 'header' => false, 'span' => 'colspan', 'children' => []], $fields);
        $tree = self::table(
            [['type' => 'table_body', 'children' => [self::row([$origin, $mark])]]],
            [['type' => 'table_column'], ['type' => 'table_column']],
        );

        self::assertSame("| A | < |\n", Carve::writeWithDiagnostics($tree)->source);
        self::assertSame($expected, self::losses($tree));
    }

    public function testAMarkInABodyHeaderRowReadsBackWithHeaderFalse(): void
    {
        $source = "{body-rows=1,1 body-header-rows=1,0}\n| a | < |\n| b | c |\n| d | e |\n";
        $tree = TreeEncoder::decodedWithOffsets(Carve::parse($source), $source);
        $row = $tree['children'][0]['children'][0]['children'][0]['children'];

        self::assertTrue($row[0]['header']);
        self::assertFalse($row[1]['header']);
        $result = Carve::writeWithDiagnostics($tree);
        self::assertSame($source, $result->source);
        self::assertSame([], $result->diagnostics);
    }
}
