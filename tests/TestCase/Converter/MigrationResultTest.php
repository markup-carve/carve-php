<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\BbcodeToCarve;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

final class MigrationResultTest extends TestCase
{
    public function testEveryImporterUsesTheVersionedFidelityEnvelope(): void
    {
        $results = [
            (new HtmlToCarve())->convertWithFidelityReport('<section><p>x</p></section>'),
            (new MarkdownToCarve())->convertWithFidelityReport('**strong**'),
            (new DjotToCarve())->convertWithFidelityReport('_emphasis_'),
            (new BbcodeToCarve())->convertWithFidelityReport('[b]strong[/b]'),
        ];

        foreach ($results as $result) {
            $report = $result->report();
            self::assertSame(2, $report['schemaVersion']);
            self::assertNotSame('', $report['sourceFormat']);
            foreach ($report['diagnostics'] as $diagnostic) {
                self::assertContains($diagnostic['fidelity'], ['preserved', 'normalized', 'degraded', 'dropped']);
                self::assertContains($diagnostic['confidence'], ['exact', 'inferred', 'fallback']);
            }
        }
    }
}
