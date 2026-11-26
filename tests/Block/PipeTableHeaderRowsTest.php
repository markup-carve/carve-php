<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6663: `header-rows` sets the head of a pipe table (C2-B-186). The render cases CC-2237 to CC-2240 are held on
 * carve#6837 for their final line feed; these check the same markup without it.
 */
final class PipeTableHeaderRowsTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function cases(): array
    {
        $out = [];
        $all = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/spec/conformance/clause-cases.json'), true);
        foreach ($all['cases'] as $case) {
            if (in_array($case['id'], ['CC-2237', 'CC-2238', 'CC-2239', 'CC-2240'], true)) {
                $out[$case['id']] = [$case['id'], $case['input'], $case['expected']['html']];
            }
        }

        return $out;
    }

    #[DataProvider('cases')]
    public function testRendersTheHeadTheCountSets(string $id, string $source, string $html): void
    {
        self::assertSame(rtrim($html, "\n"), Carve::renderWithDiagnostics($source)->html, $id);
    }

    public function testACountAboveTheRowsTakesThemAll(): void
    {
        self::assertSame(
            "<table>\n  <thead>\n    <tr><td>a</td></tr>\n    <tr><td>b</td></tr>\n  </thead>\n</table>",
            Carve::renderWithDiagnostics("{header-rows=3}\n| a |\n| b |\n")->html,
        );
    }
}
