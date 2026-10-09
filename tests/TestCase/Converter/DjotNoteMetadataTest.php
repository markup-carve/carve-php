<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\TestCase;

class DjotNoteMetadataTest extends TestCase
{
    public function testDefinitionAttributesDoNotReachLaterContent(): void
    {
        $rows = json_decode(file_get_contents(__DIR__ . '/../../fixtures/djot-note-metadata.json'), true, flags: JSON_THROW_ON_ERROR);
        $importer = new DjotToCarve();
        $converter = new CarveConverter();
        foreach ($rows as $row) {
            $result = $importer->convertWithFidelityReport($row['source']);
            self::assertSame($importer->convert($row['source']), $result->value, $row['name']);
            $losses = array_values(array_filter($result->diagnostics, static fn ($diagnostic): bool => $diagnostic->code === 'djot-footnote-definition-attributes-dropped'));
            self::assertSame(array_map(static fn (int $line): string => 'line:' . $line, $row['lossLines']), array_map(static fn ($diagnostic): ?string => $diagnostic->path, $losses), $row['name']);
            foreach ($losses as $loss) {
                self::assertSame('dropped', $loss->fidelity, $row['name']);
                self::assertSame('exact', $loss->confidence, $row['name']);
            }
            if (isset($row['html'])) {
                $html = trim($converter->convert($result->value));
                $html = preg_replace('/ aria-label="[^"]*"/', '', $html);
                $html = str_replace('↩︎', '↩', $html);
                $html = preg_replace('/(<li>)\n/', '$1', $html);
                $html = preg_replace('/\n(<\/li>)/', '$1', $html);
                $html = preg_replace('/<\/?tbody>/', '', $html);
                $html = preg_replace('/<ol type="([^"]+)" start="([^"]+)">/', '<ol start="$2" type="$1">', $html);
                $html = preg_replace('/>\s+</', '><', $html);
                self::assertSame($row['html'], $html, $row['name']);
            }
        }
    }
}
