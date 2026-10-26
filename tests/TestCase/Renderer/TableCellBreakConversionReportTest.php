<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

class TableCellBreakConversionReportTest extends TestCase
{
    public function testBreakLossesAreReportedWithAccurateBounds(): void
    {
        $cases = json_decode(file_get_contents(__DIR__ . '/../../fixtures/table-cell-break-conversion-reports.json'), true, 512, JSON_THROW_ON_ERROR);
        $renderer = new CarveRenderer();
        foreach ($cases as $case) {
            $document = (new AstCodec())->decode($case['ast']);
            // The trailing fixture predates carve#3735; that break is now spellable.
            $expected = $case['name'] === 'trailing' ? [] : $case['diagnostics'];
            foreach ([0, 1, 100] as $maximum) {
                $renderer->beginConversionDiagnosticCollection($maximum);
                $source = $renderer->render($document);
                $report = $renderer->finishConversionDiagnosticCollection();
                $this->assertSame(count($expected), $report['totalDiagnostics'], $case['name']);
                $this->assertSame(count($expected) > $maximum, $report['truncated'], $case['name']);
                $rows = array_map(static fn (array $d): array => array_intersect_key($d, array_flip(['code', 'node', 'field'])), $report['diagnostics']);
                $this->assertSame(array_slice($expected, 0, $maximum), $rows, $case['name']);
                $lost = count(array_filter($expected, static fn (array $d): bool => $d['node'] === 'hard_break'));
                $before = preg_match_all('/<br(?:\s*\/?)?>/', (new HtmlRenderer())->render($document));
                $after = preg_match_all('/<br(?:\s*\/?)?>/', (new CarveConverter())->convert($source));
                $this->assertSame($lost, $before - $after, $case['name']);
            }
        }
    }

    public function testFlattenedCellPaddingIsIdempotent(): void
    {
        foreach (['| x \ | b |', '| `a\b` \ | b |', '| </#h> \ | b |'] as $line) {
            $source = $line . "\n";
            $formatted = CarveConverter::toCarve($source);
            $this->assertSame($formatted, CarveConverter::toCarve($formatted));
        }
    }
}
