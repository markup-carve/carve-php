<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Json;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class TreeMigrationParityTest extends TestCase
{
    public function testRetiredAttributesAndEmptyCellsAreNormalized(): void
    {
        $tree = self::document([
            [

                'type' => 'table',
                'rows' => [
                    [

                        'type' => 'table_row',
                        'cells' => [
                            [
                                'type' => 'table_cell',
                                'header' => false,
                                'children' => [],
                                'attrs' => ['classes' => ['x'], 'order' => []],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $cell = Carve::migrateTree($tree)['document']['children'][0]['children'][0]['children'][0]['children'][0];
        self::assertSame(['type' => 'cell_paragraph', 'children' => []], $cell['children'][0]);
        self::assertSame(['classes' => ['x']], $cell['attrs']);
    }

    public function testStoredCodeTitleWinsOverDuplicateAttribute(): void
    {
        foreach (['header title', 'attribute title'] as $effective) {
            $tree = self::document([
                [
                    'type' => 'code_block',
                    'content' => "code\n",
                    'header' => $effective,
                    'attrs' => [

                        'keyValues' => [

                            'title' => $effective === 'header title'
                    ? 'attribute title' : 'header title',
                        ], 'order' => ['title'],
                    ],
                ],
            ]);
            $code = Carve::migrateTree($tree)['document']['children'][0];
            self::assertSame([['type' => 'text', 'value' => $effective]], $code['title']);
            self::assertArrayNotHasKey('attrs', $code);
        }
    }

    public function testTableCellTabAndPositionSurvive(): void
    {
        $position = self::position(2, 3);
        $tree = self::document([
            [

                'type' => 'table',
                'rows' => [
                    [

                        'type' => 'table_row',
                        'cells' => [
                            [

                                'type' => 'table_cell',
                                'header' => false,
                                'children' => [
                                    ['type' => 'text', 'value' => "\t", 'pos' => $position],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $paragraph = Carve::migrateTree($tree)['document']['children'][0]['children'][0]['children'][0]['children'][0]['children'][0];
        self::assertSame($position, $paragraph['pos']);
        self::assertSame("\t", $paragraph['children'][0]['value']);
    }

    public function testSpanPlaceholderDoesNotDemoteHeaderRow(): void
    {
        $tree = self::document([
            [

                'type' => 'table',
                'rows' => [
                    [

                        'type' => 'table_row',
                        'cells' => [
                            [

                                'type' => 'table_cell',
                                'header' => true,
                                'children' => [['type' => 'text', 'value' => 'Wide']],
                                'colspan' => 2,
                            ],
                            ['type' => 'table_cell', 'header' => false, 'children' => [], 'span' => 'colspan'],
                            ['type' => 'table_cell', 'header' => true, 'children' => [['type' => 'text', 'value' => 'Last']]],
                        ],
                    ],
                    [

                        'type' => 'table_row',
                        'cells' => [
                            ['type' => 'table_cell', 'header' => false, 'children' => [['type' => 'text', 'value' => 'a']]],
                            ['type' => 'table_cell', 'header' => false, 'children' => [['type' => 'text', 'value' => 'b']]],
                            ['type' => 'table_cell', 'header' => false, 'children' => [['type' => 'text', 'value' => 'c']]],
                        ],
                    ],
                ],
            ],
        ]);
        $groups = Carve::migrateTree($tree)['document']['children'][0]['children'];
        self::assertSame(['table_head', 'table_body'], array_column($groups, 'type'));
    }

    public function testFinalLineBreakRemainsInsideLastLine(): void
    {
        $tree = self::document([
            [

                'type' => 'line_block',
                'children' => [
                    [

                        'type' => 'paragraph',
                        'children' => [
                            ['type' => 'text', 'value' => 'a'], ['type' => 'hard_break'],
                        ],
                    ],
                ],
            ],
        ]);
        $lines = Carve::migrateTree($tree)['document']['children'][0]['children'];
        self::assertCount(1, $lines);
        self::assertSame(['text', 'hard_break'], array_column($lines[0]['children'], 'type'));
    }

    public function testCurrentUnicodeTreeKeepsBytePositions(): void
    {
        $source = "éa\n";
        $tree = json_decode(Carve::encodeTree(Carve::parse($source)), true, 4096, JSON_THROW_ON_ERROR);
        self::assertSame($tree, Carve::migrateTree($tree)['document']);
        self::assertSame($tree, Carve::migrateTree($tree, ['source' => $source])['document']);
    }

    public function testLegacyColumnDropsRetiredFieldsLikeJs(): void
    {
        $tree = self::document([
            [
                'type' => 'table',
                'rows' => [
                    [

                        'type' => 'table_row',
                        'cells' => [
                            ['type' => 'table_cell', 'header' => false, 'children' => [['type' => 'text', 'value' => 'x']]],
                        ],
                    ],
                ],
                'columns' => [
                    ['type' => 'table_column', 'align' => 'left', 'attrs' => ['id' => 'col'], 'resolvedAlign' => 'right'],
                ],
            ],
        ]);
        $columns = Carve::migrateTree($tree)['document']['children'][0]['columns'];
        self::assertSame([['type' => 'table_column', 'align' => 'left']], $columns);
    }

    /**
     * @param array<int, array<string, mixed>> $children
     *
     * @return array<string, mixed>
     */
    private static function document(array $children): array
    {
        return ['type' => 'document', 'astVersion' => '1.2', 'children' => $children];
    }

    /**
     * @return array<string, int>
     */
    private static function position(int $start, int $end): array
    {
        return [

            'startLine' => 1,
            'endLine' => 1,
            'startColumn' => $start,
            'endColumn' => $end,
            'startOffset' => $start - 1,
            'endOffset' => $end - 1,
        ];
    }
}
