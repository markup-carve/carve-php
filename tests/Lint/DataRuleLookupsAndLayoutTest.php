<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rows that read id records, line lookups, layout entries and the C2-W-150 operators, as carve-js reports them.
 */
final class DataRuleLookupsAndLayoutTest extends TestCase
{
    /**
     * @return array<string, array{string, list<array{int, int}>}>
     */
    public static function cases(): array
    {
        return [
            'block-marker-as-text' => ["{.x\npara", [[0, 3]]],
            'broken-fragment-link' => ['[x](#no)', [[0, 8]]],
            'broken-crossref' => ['</#no>', [[0, 6]]],
            'uneven-list-indent' => ["  - a\n- b", [[6, 9]]],
            'list-marker-below-content-column' => ["- a\n  - b\n * c\n", [[11, 14]]],
            'ordered-marker-right-aligned' => [" 9. a\n10. b", [[6, 11]]],
            'description-separator-below-content-column' => ["- :: term\n: desc\n", [[10, 16]]],
            'unresolved-reference-link' => ["[a][nope]\n", [[0, 9]]],
            'html-comment-renders-as-text' => ["a <!-- b --> c\n", [[2, 12]]],
        ];
    }

    /**
     * @param string $source
     * @param list<array{int, int}> $expected
     */
    #[DataProvider('cases')]
    public function testRowRunsFromData(string $source, array $expected): void
    {
        $code = (string)$this->dataName();
        $previous = getenv('CARVE_LINT_RULES');
        putenv('CARVE_LINT_RULES=data');
        try {
            $found = [];
            foreach (Carve::lint($source) as $finding) {
                if ($finding->code === $code) {
                    $found[] = [$finding->startOffset, $finding->endOffset];
                }
            }
            self::assertSame($expected, $found);
        } finally {
            putenv($previous === false ? 'CARVE_LINT_RULES' : 'CARVE_LINT_RULES=' . $previous);
        }
    }

    public function testListItemBodyFindingNamesItsBlock(): void
    {
        $previous = getenv('CARVE_LINT_RULES');
        putenv('CARVE_LINT_RULES=data');
        try {
            $paths = [];
            foreach (Carve::lint("- a\n\n b") as $finding) {
                if ($finding->code === 'list-item-body-below-content-column') {
                    $paths[] = $finding->path;
                }
            }
            self::assertSame(['/children/0/children/0/children/1'], $paths);
        } finally {
            putenv($previous === false ? 'CARVE_LINT_RULES' : 'CARVE_LINT_RULES=' . $previous);
        }
    }
}
