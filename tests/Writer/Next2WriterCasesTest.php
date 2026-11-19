<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Writer\CarveWriteError;
use PHPUnit\Framework\TestCase;

final class Next2WriterCasesTest extends TestCase
{
    public function testPinnedWriterCases(): void
    {
        $spec = json_decode(
            (string)file_get_contents(__DIR__ . '/../../spec/conformance/clause-cases.json'),
            true,
            4096,
            JSON_THROW_ON_ERROR,
        );
        $ids = ['CC-499', 'CC-1041', 'CC-1042', 'CC-1047'];
        $seen = [];
        foreach ($spec['cases'] as $case) {
            if (!in_array($case['id'], $ids, true)) {
                continue;
            }
            $seen[] = $case['id'];
            $result = Carve::writeWithDiagnostics($case['tree'], null, $case['options'] ?? []);
            self::assertSame($case['expected']['carve'], $result->source, $case['id']);
            self::assertSame([], $result->diagnostics, $case['id'] . ' diagnostics');
        }
        self::assertCount(count($ids), $seen);
    }

    public function testDecorativeImageIsAWriterLoss(): void
    {
        $spec = json_decode(
            (string)file_get_contents(__DIR__ . '/../../spec/conformance/clause-cases.json'),
            true,
            4096,
            JSON_THROW_ON_ERROR,
        );
        $case = array_values(array_filter($spec['cases'], static fn (array $entry): bool => $entry['id'] === 'CC-515'))[0];
        try {
            Carve::write($case['tree']);
            self::fail('CC-515 must refuse a decorative field');
        } catch (CarveWriteError $error) {
            self::assertSame('field-unspellable', $error->diagnostics[0]->code);
            self::assertSame('/children/0/children/0/decorative', $error->diagnostics[0]->path);
        }
    }
}
