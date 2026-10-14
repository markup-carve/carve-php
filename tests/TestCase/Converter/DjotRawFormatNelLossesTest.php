<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotRawFormatNelLossesTest extends TestCase
{
    public function testRawFormatsContainingNelReportLossesOutsideImageAlternatives(): void
    {
        $controls = json_decode(file_get_contents(__DIR__ . '/../../fixtures/djot-raw-format-nel-losses.json'), true, flags: JSON_THROW_ON_ERROR);
        $converter = new DjotToCarve();
        foreach ($controls as $row) {
            $report = $converter->convertWithFidelityReport($row['source']);
            $this->assertSame($converter->convert($row['source']), $report->value, $row['source']);
            $diagnostics = array_filter($report->diagnostics, static fn ($diagnostic): bool => str_contains($diagnostic->message, 'raw-format identifier containing U+0085'));
            $this->assertSame(array_map(static fn (int $line): string => 'line:' . $line, $row['lines']), array_values(array_map(static fn ($diagnostic): ?string => $diagnostic->path, $diagnostics)), $row['source']);
        }
    }
}
