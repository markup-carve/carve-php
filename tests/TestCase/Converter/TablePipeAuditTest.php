<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

class TablePipeAuditTest extends TestCase
{
    public function testTablePipeImportsAndRoundTrips(): void
    {
        $cases = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/table-pipe-audit.json'), true, flags: JSON_THROW_ON_ERROR);
        $converter = new CarveConverter();
        foreach ($cases as $case) {
            $source = match ($case['mode']) {
                'md' => (new MarkdownToCarve())->convert($case['source']),
                'native-export' => (new MarkdownToCarve())->convert((new MarkdownRenderer())->render($converter->parse($case['source']))),
                default => (new DjotToCarve())->convert($case['source']),
            };
            $html = $converter->convert($source);
            foreach ($case['contains'] as $fragment) {
                self::assertStringContainsString($fragment, $html, $case['mode'] . ' ' . $case['name']);
            }
            foreach ($case['excludes'] ?? [] as $fragment) {
                self::assertStringNotContainsString($fragment, $html, $case['mode'] . ' ' . $case['name']);
            }
            if (isset($case['cells'])) {
                preg_match_all('/<th\b/', $html, $cells);
                self::assertCount($case['cells'], $cells[0], $case['name']);
            }
            if (isset($case['tables'])) {
                preg_match_all('/<table\b/', $html, $tables);
                self::assertCount($case['tables'], $tables[0], $case['name']);
            }
            self::assertSame($html, $converter->convert((new CarveRenderer())->render($converter->parse($source))), $case['name']);
        }
    }
}
