<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ListStyleDroppedTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function styles(): array
    {
        return [
            'lower alpha' => ["a. one\na. two\n", "1. one\n2. two\n", 'lower-alpha'],
            'upper alpha' => ["A. one\n", "1. one\n", 'upper-alpha'],
            'lower roman' => ["i. one\n", "1. one\n", 'lower-roman'],
            'upper roman' => ["I. one\n", "1. one\n", 'upper-roman'],
            'roman start' => ["iv) x\nv) y\n", "4) x\n5) y\n", 'lower-roman'],
        ];
    }

    #[DataProvider('styles')]
    public function testStyledListReportsOneMarkdownLoss(string $source, string $expected, string $style): void
    {
        foreach ([false, true] as $roundTrip) {
            $renderer = new MarkdownRenderer();
            $renderer->setRoundTripMode($roundTrip);
            $report = (new CarveConverter(renderer: $renderer))->convertWithReport($source);
            self::assertSame($expected, $report->value);
            self::assertSame(1, $report->totalLosses);
            self::assertSame('list-style-dropped', $report->losses[0]['code']);
            self::assertSame($style, $report->losses[0]['style']);
            self::assertSame('block', $report->losses[0]['nodeType']);
            self::assertArrayHasKey('pos', $report->losses[0]);
        }
    }

    public function testDecimalListHasNoStyleLoss(): void
    {
        $report = (new CarveConverter(renderer: new MarkdownRenderer()))->convertWithReport("1. one\n2. two\n");
        self::assertSame([], $report->losses);
    }

    public function testListsReportInDocumentOrder(): void
    {
        $source = "a. x\n\n> I. quoted\n\n- y\n\n  i. nested\n";
        $report = (new CarveConverter(renderer: new MarkdownRenderer()))->convertWithReport($source);
        self::assertSame(['lower-alpha', 'upper-roman', 'lower-roman'], array_column($report->losses, 'style'));
    }
}
