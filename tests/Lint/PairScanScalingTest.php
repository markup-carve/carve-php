<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use Closure;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Lint\NodeRulesA;
use PHPUnit\Framework\TestCase;

final class PairScanScalingTest extends TestCase
{
    public function testInvalidOpenersDoNotSearchLaterClosers(): void
    {
        $small = str_repeat('x_a_ ', 500);
        $large = str_repeat('x_a_ ', 2000);
        self::assertSame([], NodeRulesA::pairRanges($small, '_', 'djot-emphasis-underscore'));
        self::assertSame([], NodeRulesA::pairRanges($large, '_', 'djot-emphasis-underscore'));
        $options = ['rules' => ['djot-emphasis-underscore' => 'on']];
        self::assertSame([], Carve::lint($large . "\n", $options));

        $smallTime = self::bestTime(static fn (): array => NodeRulesA::pairRanges($small, '_', 'djot-emphasis-underscore'));
        $largeTime = self::bestTime(static fn (): array => NodeRulesA::pairRanges($large, '_', 'djot-emphasis-underscore'));
        self::assertLessThan($smallTime * 7, $largeTime);
    }

    /**
     * @param \Closure(): array<mixed> $scan
     */
    private static function bestTime(Closure $scan): int
    {
        $best = PHP_INT_MAX;
        for ($trial = 0; $trial < 3; $trial++) {
            $start = hrtime(true);
            $scan();
            $best = min($best, hrtime(true) - $start);
        }

        return $best;
    }
}
