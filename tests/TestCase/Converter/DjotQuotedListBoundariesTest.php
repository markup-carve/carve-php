<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

final class DjotQuotedListBoundariesTest extends TestCase
{
    public function testQuoteAndListOwnership(): void
    {
        $cases = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/djot-quoted-list-boundaries.json'), true, 512, JSON_THROW_ON_ERROR);
        $importer = new DjotToCarve();
        $renderer = new CarveConverter();
        foreach ($cases as $case) {
            self::assertSame($renderer->convert($case['carve']), $renderer->convert($importer->convert($case['source'])), $case['source']);
        }
    }

    public function testMultilineCodeFallbackReportsPlainExportLoss(): void
    {
        $result = (new DjotToCarve())->convertWithFidelityReport("a `b\n> c` d\n");
        $losses = array_values(array_filter($result->report()['diagnostics'], static fn (array $row): bool => $row['code'] === 'structure-unspellable'));
        self::assertCount(1, $losses);
        self::assertSame('line:1', $losses[0]['path']);
        self::assertSame('Multiline inline code uses raw HTML; plain-text export omits it.', $losses[0]['message']);
    }

    public function testContainerPrefixesDoNotReportCodeLoss(): void
    {
        foreach (["> a `b\n> c` d\n", "- > a `b\n  > c` d\n", "ref[^n]\n\n[^n]: a\n\np `b\nc` d\n", "ref[^n]\n\n[^n]: a\n> p `b\n> c` d\n"] as $source) {
            $diagnostics = (new DjotToCarve())->convertWithFidelityReport($source)->report()['diagnostics'];
            self::assertNotContains('structure-unspellable', array_column($diagnostics, 'code'), $source);
        }
    }

    public function testOutputGrowthIsLinearForWideNumericListMarkers(): void
    {
        $importer = new DjotToCarve();
        $renderer = new CarveConverter();
        foreach ([64, 128, 256] as $size) {
            $source = str_repeat('0', $size) . "1. > a\n" . str_repeat(" > b\n", $size);
            $result = $importer->convert($source);
            self::assertLessThanOrEqual(strlen($source) * 2, strlen($result));
            self::assertSame($renderer->convert("1. > a\n" . str_repeat("   > b\n", $size)), $renderer->convert($result));
        }
    }

    public function testMultilineFootnoteCodeLossUsesTheOriginalOpener(): void
    {
        $result = (new DjotToCarve())->convertWithFidelityReport("ref[^n]\n\n[^n]: a `b\nc` d\n");
        $losses = array_values(array_filter($result->report()['diagnostics'], static fn (array $row): bool => $row['code'] === 'structure-unspellable'));
        self::assertCount(1, $losses);
        self::assertSame('line:3', $losses[0]['path']);
        self::assertSame('Multiline inline code uses raw HTML; plain-text export omits it.', $losses[0]['message']);
    }

    public function testNestedNotesWithLazyContinuationHaveBoundedOutput(): void
    {
        $importer = new DjotToCarve();
        $renderer = new CarveConverter();
        foreach ([16, 32, 64] as $size) {
            $headers = [];
            $references = [];
            for ($n = 0; $n < $size; $n++) {
                $headers[] = str_repeat(' ', $n) . "[^n{$n}]: a";
                $references[] = "[^n{$n}]";
            }
            $source = implode("\n\n", $headers) . "\n" . str_repeat("lazy\n", 4096) . "\n" . implode(' ', $references) . "\n";
            $result = $importer->convert($source);
            self::assertLessThanOrEqual(strlen($source) * 2, strlen($result));
            $html = $renderer->convert($result);
            self::assertSame(4096, substr_count($html, 'lazy'));
            self::assertSame($size, substr_count($html, 'role="doc-noteref"'));
        }
    }

    public function testDeepInlineListsWithLazyQuotedTextHaveBoundedOutput(): void
    {
        $importer = new DjotToCarve();
        $renderer = new CarveConverter();
        foreach ([16, 32, 64] as $size) {
            $source = str_repeat('- > ', $size) . "a\n" . str_repeat(" > lazy\n", 1024);
            $result = $importer->convert($source);
            self::assertLessThanOrEqual(strlen($source) * 2, strlen($result));
            self::assertSame(1024, substr_count($renderer->convert($result), 'lazy'));
        }
    }
}
