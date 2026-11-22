<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-B-055: only an open deeper list can supply the column of a later marker.
 */
final class NestedListMarkerOpenRelationshipTest extends TestCase
{
    /**
     * @return array<string, array{string, list<array{string, int, int}>}>
     */
    public static function cases(): array
    {
        return [
            'paragraph leaves the deeper list open' => [
                "- a\n  - deep\n\n  para\n - shallow\n",
                [
                    ['list-item-body-below-content-column', 22, 31],
                    ['list-marker-below-content-column', 22, 31],
                ],
            ],
            'each shifted marker is reported' => [
                "- a\n    - x\n   - y\n   - z\n",
                [
                    ['list-marker-below-content-column', 15, 18],
                    ['list-marker-below-content-column', 22, 25],
                ],
            ],
            'heading closes the deeper list' => [
                "- a\n    - x\n\n  # heading\n\n   - y\n",
                [],
            ],
        ];
    }

    /**
     * @param string $source
     * @param list<array{string, int, int}> $expected
     */
    #[DataProvider('cases')]
    public function testBothLintModes(string $source, array $expected): void
    {
        $previous = getenv('CARVE_LINT_RULES');
        try {
            foreach (['hand', 'data'] as $mode) {
                putenv($mode === 'data' ? 'CARVE_LINT_RULES=data' : 'CARVE_LINT_RULES');
                $actual = array_map(
                    static fn ($item): array => [$item->code, $item->startOffset, $item->endOffset],
                    Carve::lint($source),
                );
                self::assertSame($expected, $actual, $mode);
            }
        } finally {
            putenv($previous === false ? 'CARVE_LINT_RULES' : 'CARVE_LINT_RULES=' . $previous);
        }
    }
}
