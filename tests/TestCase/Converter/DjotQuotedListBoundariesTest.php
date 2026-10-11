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
        self::assertSame('line:2', $losses[0]['path']);
        self::assertSame('Multiline inline code containing a quote marker uses raw HTML; plain-text export omits it.', $losses[0]['message']);
    }
}
