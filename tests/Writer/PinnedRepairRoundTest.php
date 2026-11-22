<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PinnedRepairRoundTest extends TestCase
{
    #[DataProvider('repairCases')]
    public function testRepairRoundCaseMatchesPinnedBytes(string $id, string $input, string $expected, array $options): void
    {
        $written = Carve::formatWithDiagnostics($input, $options);
        self::assertSame($expected, $written->source, $id);
        self::assertSame([], $written->diagnostics, $id . ' diagnostics');
    }

    public static function repairCases(): iterable
    {
        $spec = json_decode(
            (string)file_get_contents(__DIR__ . '/../../spec/conformance/clause-cases.json'),
            true,
            4096,
            JSON_THROW_ON_ERROR,
        );
        $byId = [];
        foreach ($spec['cases'] as $case) {
            $byId[$case['id']] = $case;
        }
        foreach (['CC-1565', 'CC-1566', 'CC-1567', 'CC-1568'] as $id) {
            if (!isset($byId[$id])) {
                throw new RuntimeException('Missing pinned repair case ' . $id);
            }
            $case = $byId[$id];

            yield $id => [$id, $case['input'], $case['expected']['carve'], $case['options'] ?? []];
        }
    }
}
