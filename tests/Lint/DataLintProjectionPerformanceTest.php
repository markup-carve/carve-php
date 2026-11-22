<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

/**
 * A repeated ordinary table must not pay for full data records whose unconditional rule terms cannot match.
 */
final class DataLintProjectionPerformanceTest extends TestCase
{
    public function testDataModeStaysNearTheHandwrittenCost(): void
    {
        if (getenv('CARVE_PERF_TEST') !== '1') {
            self::markTestSkipped('Run on a stable CPU with CARVE_PERF_TEST=1');
        }
        $source = "|= Key |= Name |= Value |= Code |\n";
        $length = strlen($source);
        for ($i = 0; $length < 32 * 1024; $i++) {
            $row = "| r$i | alpha delta | *v$i* | `c` |\n";
            $source .= $row;
            $length += strlen($row);
        }
        self::assertLessThanOrEqual(1.15, self::dataRatio($source));
    }

    public function testListLookupProjectionCost(): void
    {
        if (getenv('CARVE_PERF_TEST') !== '1') {
            self::markTestSkipped('Run on a stable CPU with CARVE_PERF_TEST=1');
        }
        $source = '';
        $length = 0;
        for ($i = 0; $length < 32 * 1024; $i++) {
            $row = "- item $i alpha delta eta *em* `c$i`\n";
            if ($i % 5 === 0) {
                $row .= "  - nested alpha delta eta\n  - nested two\n";
            }
            $source .= $row;
            $length += strlen($row);
        }
        self::assertLessThanOrEqual(1.25, self::dataRatio($source));
    }

    private static function dataRatio(string $source): float
    {
        $previous = getenv('CARVE_LINT_RULES');
        $samples = ['hand' => [], 'data' => []];
        $findings = [];
        try {
            for ($round = 0; $round < 3; $round++) {
                foreach ($round % 2 === 0 ? ['hand', 'data'] : ['data', 'hand'] as $mode) {
                    putenv($mode === 'data' ? 'CARVE_LINT_RULES=data' : 'CARVE_LINT_RULES');
                    Carve::lint($source);
                    $start = self::cpuMicros();
                    $found = Carve::lint($source);
                    $samples[$mode][] = self::cpuMicros() - $start;
                    $findings[$mode] = array_map(static fn ($item): array => $item->toArray(), $found);
                }
            }
        } finally {
            putenv($previous === false ? 'CARVE_LINT_RULES' : 'CARVE_LINT_RULES=' . $previous);
        }
        self::assertSame($findings['hand'], $findings['data']);
        sort($samples['hand']);
        sort($samples['data']);

        return $samples['data'][1] / $samples['hand'][1];
    }

    private static function cpuMicros(): int
    {
        $usage = getrusage();
        if ($usage === false) {
            self::fail('CPU usage is unavailable');
        }

        return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1_000_000
            + $usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec'];
    }
}
