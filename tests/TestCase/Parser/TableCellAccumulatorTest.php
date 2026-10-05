<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Parser\Block\TableCellAccumulator;
use MarkupCarve\Carve\Parser\Block\TableParser;
use PHPUnit\Framework\TestCase;

class TableCellAccumulatorTest extends TestCase
{
    public function testFragmentsPreserveMergedTextAndCodeState(): void
    {
        $parser = new TableParser();
        $merged = [' `a ', ' b ', ''];
        $cells = new TableCellAccumulator($parser, $merged);
        foreach ([[' | x ', ' `` ', ''], ['`', '`'], ['', '``', 'c'], [' d ', ' e ', '', 'f']] as $row) {
            $cells->append($row);
            $merged = $parser->mergeCellContents($merged, $row);
            self::assertSame($merged, $cells->contents());
            $open = [];
            foreach ($merged as $index => $cell) {
                $width = $parser->openCodeSpanDelimiter($cell);
                if ($width > 0) {
                    $open[$index] = $width;
                }
            }
            self::assertSame($open, $cells->openDelimiters());
        }
    }

    public function testCustomTableParserHooksRemainInUse(): void
    {
        $parser = new class extends TableParser {
            public function mergeCellContents(array $baseCells, array $continuationCells): array
            {
                return ['custom'];
            }

            public function openCodeSpanDelimiter(string $line): int
            {
                return 7;
            }
        };
        $cells = new TableCellAccumulator($parser, ['base']);
        $cells->append(['next']);
        self::assertSame(['custom'], $cells->contents());
        self::assertSame([0 => 7], $cells->openDelimiters());
    }

    public function testOnlyNewFragmentBytesAreScanned(): void
    {
        $parser = new class extends TableParser {
            public int $bytes = 0;

            public function advanceCodeSpanDelimiter(string $line, int $openWidth): int
            {
                $this->bytes += strlen($line);

                return parent::advanceCodeSpanDelimiter($line, $openWidth);
            }
        };
        $cells = new TableCellAccumulator($parser, ['`x']);
        for ($i = 0; $i < 4096; $i++) {
            $cells->append(['x']);
        }
        $cells->append(['`']);
        self::assertSame(4099, $parser->bytes);
        self::assertSame([], $cells->openDelimiters());
        self::assertSame('`x' . str_repeat(' x', 4096) . ' `', $cells->contents()[0]);
    }
}
