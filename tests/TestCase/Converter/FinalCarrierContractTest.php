<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlImportDiagnostic;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class FinalCarrierContractTest extends TestCase
{
    public function testIdSlotCountsSurvivingAttributes(): void
    {
        $source = "{data-carve-ref=x .c #mine k=v}\n# H\n";
        $html = (new CarveConverter(roundTripMode: true))->convert($source);
        self::assertStringContainsString('data-carve-id-slot="1"', $html);
        self::assertStringNotContainsString('data-carve-ref=', $html);
        self::assertStringContainsString('{.c #mine k=v}', (new HtmlToCarve())->convert($html));
        self::assertStringContainsString('data-carve-ref="x"', (new CarveConverter())->convert($source));
    }

    public function testAnUnmarkedWrapperKeepsItsAuthoredIdInSource(): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            self::assertSame("{#mine .c}\n# H\n", (new HtmlToCarve(importMode: $mode))->convert('<section id="mine"><h1 class="c">H</h1></section>'));
        }
    }

    public function testContradictionsReportOneWarningInEveryMode(): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            foreach (
                [
                    '<section id="mine" data-carve-explicit-id data-djot-generated-id><h1>H</h1></section>',
                    '<p><span data-carve-escaped data-djot-raw="latex">*</span></p>',
                ] as $html
            ) {
                $report = (new HtmlToCarve(importMode: $mode))->convertWithReport($html);
                $warnings = array_filter($report->diagnostics, static fn (HtmlImportDiagnostic $row): bool => $row->code === 'attribute-dropped' && $row->severity === 'warning');
                self::assertCount(1, $warnings);
            }
        }
    }
}
