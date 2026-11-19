<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class Next2BudgetCasesTest extends TestCase
{
    public function testBudgetDiagnosticCarriesItsAllowance(): void
    {
        $spec = json_decode(
            (string)file_get_contents(__DIR__ . '/../../spec/conformance/clause-cases.json'),
            true,
            4096,
            JSON_THROW_ON_ERROR,
        );
        foreach ($spec['cases'] as $case) {
            if (!in_array($case['id'], ['CC-590', 'CC-661'], true)) {
                continue;
            }
            $result = Carve::renderWithDiagnostics($case['tree'] ?? $case['input']);
            $budget = array_values(array_filter($result->diagnostics, static fn ($entry): bool => $entry->code === 'derived-text-budget'));
            self::assertCount(1, $budget, $case['id']);
            self::assertStringContainsString('1000000 bytes', $budget[0]->message, $case['id']);
        }
    }
}
