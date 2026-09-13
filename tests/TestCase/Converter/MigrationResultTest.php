<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use InvalidArgumentException;
use MarkupCarve\Carve\Converter\BbcodeToCarve;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Converter\HtmlImportDiagnostic;
use MarkupCarve\Carve\Converter\HtmlImportResult;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Converter\MigrationDiagnostic;
use MarkupCarve\Carve\Converter\MigrationResult;
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

    public function testImportersWithoutConstructEvidenceFailClosed(): void
    {
        $results = [
            (new MarkdownToCarve())->convertWithFidelityReport('plain text'),
            (new DjotToCarve())->convertWithFidelityReport('plain text'),
            (new BbcodeToCarve())->convertWithFidelityReport('plain text'),
        ];

        foreach ($results as $result) {
            self::assertSame([
                'code' => 'fidelity-unverified',
                'message' => 'The ' . $result->sourceFormat . ' importer does not yet provide construct-level fidelity evidence',
                'severity' => 'warning',
                'fidelity' => 'dropped',
                'confidence' => 'fallback',
            ], $result->diagnostics[0]->toArray());
        }
    }

    public function testHtmlReportRetainsModeAndAdapter(): void
    {
        $report = (new HtmlToCarve(importMode: 'safe', importAdapter: 'tiptap'))
            ->convertWithFidelityReport('<p>x</p>')
            ->report();

        self::assertSame('safe', $report['mode']);
        self::assertSame('tiptap', $report['adapter']);
    }

    public function testDiagnosticVocabularyIsValidated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MigrationDiagnostic('code', 'message', 'warning', 'lossless', 'exact');
    }

    public function testUnknownHtmlDiagnosticFailsClosed(): void
    {
        $converter = new class extends HtmlToCarve {
            public function mapReport(HtmlImportResult $result): MigrationResult
            {
                return $this->htmlMigrationResult($result);
            }
        };
        $result = $converter->mapReport(new HtmlImportResult(
            'value',
            'safe',
            'generic',
            [new HtmlImportDiagnostic('future-loss', 'Unknown future diagnostic', 'error')],
        ));

        self::assertSame('dropped', $result->diagnostics[0]->fidelity);
        self::assertSame('fallback', $result->diagnostics[0]->confidence);
    }
}
