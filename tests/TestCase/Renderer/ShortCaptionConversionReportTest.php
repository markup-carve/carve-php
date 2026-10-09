<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

class ShortCaptionConversionReportTest extends TestCase
{
    public function testFieldLossesAreOrderedAndDoNotChangeSource(): void
    {
        $cases = json_decode(file_get_contents(__DIR__ . '/../../fixtures/short-caption-conversion-reports.json'), true, 512, JSON_THROW_ON_ERROR);
        $renderer = new CarveRenderer();
        foreach ($cases as $case) {
            $document = (new AstCodec())->decode($case['ast']);
            $plain = $renderer->render($document);
            foreach ([0, 1, 2, 100] as $maximum) {
                $renderer->beginConversionDiagnosticCollection($maximum);
                $source = $renderer->render($document);
                $report = $renderer->finishConversionDiagnosticCollection();
                $this->assertSame($plain, $source, $case['name']);
                $this->assertSame(count($case['diagnostics']), $report['totalDiagnostics'], $case['name']);
                $this->assertSame(count($case['diagnostics']) > $maximum, $report['truncated'], $case['name']);
                $rows = array_map(static fn (array $d): array => array_intersect_key($d, array_flip(['code', 'node', 'field'])), $report['diagnostics']);
                $this->assertSame(array_slice($case['diagnostics'], 0, $maximum), $rows, $case['name']);
            }
        }
    }
}
