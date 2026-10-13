<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 CARVE-P11-056: a blank GFM body row imports as a row of empty cells,
 * pinned by the spec's importer-fidelity row `markdown-blank-table-row`.
 */
class ABlankMarkdownTableBodyRowIsKeptTest extends TestCase
{
    public function testTheSharedFidelityRowMatches(): void
    {
        $manifest = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/spec/tests/importer-fidelity/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $cases = array_values(array_filter(
            $manifest['cases'] ?? $manifest,
            static fn (mixed $case): bool => is_array($case) && ($case['id'] ?? null) === 'markdown-blank-table-row',
        ));
        $this->assertCount(1, $cases);
        $case = $cases[0];

        $result = (new MarkdownToCarve())->convertWithFidelityReport($case['input']);

        $this->assertSame($case['expected']['output'], $result->value);
        $this->assertSame(
            $case['expected']['diagnostics'],
            array_map(
                static fn (array $diagnostic): array => array_diff_key($diagnostic, ['message' => true]),
                $result->report()['diagnostics'],
            ),
        );
    }
}
