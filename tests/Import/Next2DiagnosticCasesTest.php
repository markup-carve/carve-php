<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class Next2DiagnosticCasesTest extends TestCase
{
    public function testRawTextTagSpellingDoesNotStealLaterLocation(): void
    {
        $html = '<script>"<p>"</script><p onclick="go()">x</p>';
        $result = Carve::importHtml($html);
        $losses = array_values(array_filter($result->diagnostics, static fn ($entry): bool => $entry->code === 'import-attribute-dropped'));
        self::assertCount(1, $losses);
        self::assertSame(strpos($html, '<p onclick'), $losses[0]->startOffset);
    }

    public function testPinnedImporterDiagnosticsAndBodyPartitions(): void
    {
        $spec = json_decode(
            (string)file_get_contents(__DIR__ . '/../../spec/conformance/clause-cases.json'),
            true,
            4096,
            JSON_THROW_ON_ERROR,
        );
        $ids = ['CC-480', 'CC-669', 'CC-671', 'CC-673', 'CC-675', 'CC-677', 'CC-679', 'CC-681', 'CC-1044'];
        $seen = [];
        foreach ($spec['cases'] as $case) {
            if (!in_array($case['id'], $ids, true)) {
                continue;
            }
            $seen[] = $case['id'];
            $result = Carve::importHtml($case['html']);
            if (isset($case['expected']['carve'])) {
                self::assertSame($case['expected']['carve'], $result->source, $case['id']);
            }
            foreach ($case['expected']['diagnostics'] ?? [] as $expected) {
                $matches = array_values(array_filter(
                    $result->diagnostics,
                    static fn ($entry): bool => $entry->code === $expected['code'],
                ));
                self::assertCount(1, $matches, $case['id']);
                $prefix = substr($case['html'], 0, $matches[0]->startOffset);
                $lines = explode("\n", $prefix);
                self::assertSame($expected['line'], count($lines), $case['id'] . ' line');
                self::assertSame($expected['column'], mb_strlen($lines[count($lines) - 1]) + 1, $case['id'] . ' column');
            }
        }
        self::assertCount(count($ids), $seen);
    }
}
