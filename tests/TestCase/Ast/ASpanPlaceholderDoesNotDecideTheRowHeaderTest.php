<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\TableRow;
use PHPUnit\Framework\TestCase;

class ASpanPlaceholderDoesNotDecideTheRowHeaderTest extends TestCase
{
    public function testAColspanPlaceholderDoesNotDemoteTheHeader(): void
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
                                ['type' => 'table_cell', 'header' => true, 'colspan' => 2, 'children' => [['type' => 'text', 'value' => 'a']]],
                                ['type' => 'table_cell', 'header' => false, 'span' => 'colspan', 'children' => []],
                                ['type' => 'table_cell', 'header' => true, 'children' => [['type' => 'text', 'value' => 'b']]],
                            ],
                        ],
                        [
                            'type' => 'table_row',
                            'cells' => [
                                ['type' => 'table_cell', 'header' => false, 'children' => [['type' => 'text', 'value' => '1']]],
                                ['type' => 'table_cell', 'header' => false, 'children' => [['type' => 'text', 'value' => '2']]],
                                ['type' => 'table_cell', 'header' => false, 'children' => [['type' => 'text', 'value' => '3']]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame("| a |  | b |\n| --- | --- | --- |\n| 1 | 2 | 3 |\n", CarveConverter::markdown()->render($document));
    }

    public function testARowOfOnlyPlaceholdersIsNotAHeader(): void
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
                            'cells' => [['type' => 'table_cell', 'header' => true, 'span' => 'rowspan', 'children' => []]],
                        ],
                    ],
                ],
            ],
        ]);
        $row = $document->getChildren()[0]->getChildren()[0];

        $this->assertInstanceOf(TableRow::class, $row);
        $this->assertFalse($row->isHeader());
    }
}
