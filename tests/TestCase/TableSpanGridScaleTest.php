<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Renderer\TableSpanGrid;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class TableSpanGridScaleTest extends TestCase
{
    use ScalingGuardTrait;

    public function testWideColspanAndCoveredCarets(): void
    {
        $grid = TableSpanGrid::resolve($this->mergedRows(8000));
        self::assertSame(8000, $grid[0][0]['colspan']);
        self::assertSame(2, $grid[0][0]['rowspan']);
        self::assertFalse($grid[0][0]['skip']);
        foreach (array_slice($grid[0], 1) as $cell) {
            self::assertTrue($cell['skip']);
        }
        foreach ($grid[1] as $cell) {
            self::assertTrue($cell['skip']);
        }
    }

    #[Group('scaling')]
    public function testWideMergedRowsScaleLinearly(): void
    {
        $tables = [2000 => $this->mergedRows(2000), 8000 => $this->mergedRows(8000)];
        $this->assertConversionScalesLinearly(
            static function (string $input) use ($tables): void {
                TableSpanGrid::resolve($tables[strlen($input)]);
            },
            str_repeat('x', 2000),
            str_repeat('x', 8000),
            'wide colspans and covered carets',
            2000,
            8000,
        );
    }

    public function testIndependentSpansKeepTheirAttributedGroups(): void
    {
        $converter = new CarveConverter();
        $doc = $this->groupedDocument(200);
        $html = $converter->render($doc);
        self::assertSame(200, substr_count($html, '<tbody class="group">'));
        self::assertSame(200, substr_count($html, 'rowspan="2"'));
    }

    #[Group('scaling')]
    public function testManyAttributedGroupsScaleLinearly(): void
    {
        $converter = new CarveConverter();
        $docs = [1000 => $this->groupedDocument(1000), 4000 => $this->groupedDocument(4000)];
        $this->assertConversionScalesLinearly(
            static function (string $input) use ($converter, $docs): void {
                $converter->render($docs[strlen($input)]);
            },
            str_repeat('x', 1000),
            str_repeat('x', 4000),
            'many attributed groups with independent rowspans',
            1000,
            4000,
        );
    }

    private function groupedDocument(int $count): Document
    {
        $doc = (new CarveConverter())->parse(str_repeat("| A |\n| ^ |\n", $count));
        $table = $doc->getChildren()[0];
        self::assertInstanceOf(Table::class, $table);
        $table->setRowGroups([
            'headRows' => 0,
            'footRows' => 0,
            'bodies' => array_fill(0, $count, ['headRows' => 0, 'bodyRows' => 2, 'attrs' => ['classes' => ['group']]]),
        ]);

        return $doc;
    }

    private function mergedRows(int $width): Table
    {
        $table = new Table();
        foreach ([false, true] as $carets) {
            $row = new TableRow();
            for ($c = 0; $c < $width; $c++) {
                $row->appendChild(new TableCell(spanMarker: $carets ? '^' : ($c === 0 ? null : '<')));
            }
            $table->appendChild($row);
        }

        return $table;
    }
}
