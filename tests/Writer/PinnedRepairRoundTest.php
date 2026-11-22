<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class PinnedRepairRoundTest extends TestCase
{
    public function testRepairRoundCasesMatchPinnedBytes(): void
    {
        $spec = json_decode(
            (string)file_get_contents(__DIR__ . '/../../spec/conformance/clause-cases.json'),
            true,
            4096,
            JSON_THROW_ON_ERROR,
        );
        $seen = [];
        foreach ($spec['cases'] as $case) {
            if (!in_array($case['id'], ['CC-1565', 'CC-1566', 'CC-1567', 'CC-1568'], true)) {
                continue;
            }
            $seen[] = $case['id'];
            $written = Carve::formatWithDiagnostics($case['input'], $case['options'] ?? []);
            self::assertSame($case['expected']['carve'], $written->source, $case['id']);
            self::assertSame([], $written->diagnostics, $case['id'] . ' diagnostics');
        }
        self::assertSame(['CC-1565', 'CC-1566', 'CC-1567', 'CC-1568'], $seen);
    }
}
