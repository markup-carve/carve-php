<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\RenderLossException;
use PHPUnit\Framework\TestCase;

class TableRenderLossTest extends TestCase
{
    public function testSeparateColumnAndRowSpans(): void
    {
        $source = "|= System |= Limit | < |\n| A | Cold | 20 |\n| ^ | Hot | 10 |\n";
        foreach (['markdown', 'plain', 'ansi'] as $target) {
            $converter = ($target === 'plain' ? CarveConverter::plainText() : ($target === 'markdown' ? CarveConverter::markdown() : CarveConverter::ansi()));
            $report = $converter->convertWithReport($source);
            self::assertSame(2, $report->totalLosses, $target);
            self::assertSame(['table-span-flattened', 'table-span-flattened'], array_column($report->losses, 'code'));
            self::assertSame(2, $report->losses[0]['colspan']);
            self::assertArrayNotHasKey('rowspan', $report->losses[0]);
            self::assertSame(2, $report->losses[1]['rowspan']);
            self::assertArrayNotHasKey('colspan', $report->losses[1]);
            foreach ($report->losses as $loss) {
                self::assertSame($target, $loss['target']);
                self::assertSame('block', $loss['nodeType']);
                self::assertSame('Flattened a spanning table cell into separate cells', $loss['message']);
                self::assertArrayHasKey('pos', $loss);
            }
            try {
                $converter->convertWithReport($source, strictLosses: true);
                self::fail('Strict losses accepted a flattened span');
            } catch (RenderLossException) {
            }
        }
        foreach ([CarveConverter::create(), CarveConverter::carve()] as $converter) {
            self::assertSame(0, $converter->convertWithReport($source)->totalLosses);
        }
    }

    public function testOneCellSpanningBothAxes(): void
    {
        $source = "| A | < | B |\n| ^ | < | C |\n";
        foreach (['markdown', 'plain', 'ansi'] as $target) {
            $report = ($target === 'plain' ? CarveConverter::plainText() : ($target === 'markdown' ? CarveConverter::markdown() : CarveConverter::ansi()))->convertWithReport($source);
            self::assertSame(1, $report->totalLosses, $target);
            self::assertSame(2, $report->losses[0]['colspan']);
            self::assertSame(2, $report->losses[0]['rowspan']);
        }
    }

    public function testLaterHeaderRowIsDemotedOnlyOnMarkdown(): void
    {
        $source = "|= First |\n|= Second |\n";
        $report = CarveConverter::markdown()->convertWithReport($source);
        self::assertSame(1, $report->totalLosses);
        self::assertSame('table-header-row-demoted', $report->losses[0]['code']);
        self::assertSame('Wrote a later header row as a body row', $report->losses[0]['message']);
        self::assertSame('block', $report->losses[0]['nodeType']);
        self::assertArrayHasKey('pos', $report->losses[0]);
        foreach (['plain', 'ansi'] as $target) {
            self::assertSame(0, ($target === 'plain' ? CarveConverter::plainText() : ($target === 'markdown' ? CarveConverter::markdown() : CarveConverter::ansi()))->convertWithReport($source)->totalLosses);
        }
    }

    public function testHeaderLossPrecedesItsCellsAndEachRenderReportsOnce(): void
    {
        $source = "|= A | < |\n|= B | < |\n";
        $converter = CarveConverter::markdown();
        foreach ([1, 2] as $_) {
            $report = $converter->convertWithReport($source);
            self::assertSame(3, $report->totalLosses);
            self::assertSame(
                ['table-span-flattened', 'table-header-row-demoted', 'table-span-flattened'],
                array_column($report->losses, 'code'),
            );
        }
    }
}
