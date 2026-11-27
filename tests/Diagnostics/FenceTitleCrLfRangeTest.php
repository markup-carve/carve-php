<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Diagnostics;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FenceTitleCrLfRangeTest extends TestCase
{
    /**
     * @return array<string, array{string, int, int}>
     */
    public static function openers(): array
    {
        return [
            'code fence with UTF-8' => ["```é\r\nx\r\n```\r\n", 3, 5],
            'div with further words' => ["::: note more\r\nx\r\n:::\r\n", 9, 13],
            'lone CR stays in line' => ["```é\rx\nbody\n```", 3, 7],
        ];
    }

    #[DataProvider('openers')]
    public function testFindingEndsAtLogicalLineEnd(string $source, int $start, int $end): void
    {
        $findings = array_values(array_filter(
            Carve::parseWithDiagnostics($source)->diagnostics,
            static fn ($finding): bool => $finding->code === 'fence-title-syntax',
        ));

        self::assertCount(1, $findings);
        self::assertSame([$start, $end], [$findings[0]->startOffset, $findings[0]->endOffset]);
    }
}
