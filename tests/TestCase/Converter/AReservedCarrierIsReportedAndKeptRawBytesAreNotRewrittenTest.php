<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AReservedCarrierIsReportedAndKeptRawBytesAreNotRewrittenTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, array<string>}>
     */
    public static function rows(): iterable
    {
        /** @var array<array{id: string, mode: string, html: string, source: string, rows: array<string>}> $rows */
        $rows = json_decode(
            (string)file_get_contents(__DIR__ . '/../../fixtures/reserved_carrier_import_rows.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        foreach ($rows as $row) {
            yield $row['id'] . ' ' . $row['mode'] => [$row['mode'], $row['html'], $row['source'], $row['rows']];
        }
    }

    /**
     * @param string $mode
     * @param string $html
     * @param string $source
     * @param array<string> $expectedRows
     */
    #[DataProvider('rows')]
    public function testImport(string $mode, string $html, string $source, array $expectedRows): void
    {
        $importer = new HtmlToCarve(importMode: $mode);
        $result = $importer->convertWithReport($html);
        $this->assertSame($source, $result->value);
        foreach ([$result->report(), $importer->convertToAstWithReport($html)->report()] as $report) {
            $rows = [];
            foreach ($report['diagnostics'] as $row) {
                $rows[] = implode('|', [
                    $row['code'],
                    $row['severity'],
                    $row['fidelity'],
                    $row['confidence'],
                    $row['path'],
                    $row['message'],
                ]);
            }
            $this->assertSame($expectedRows, $rows);
        }
    }
}
