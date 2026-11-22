<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

/**
 * Nested block records should materialize optional fields only for rules that read them.
 */
final class DataLintLazyProjectionPerformanceTest extends TestCase
{
    public function testNestedProjectionCost(): void
    {
        if (getenv('CARVE_PERF_TEST') !== '1') {
            self::markTestSkipped('Run on a stable CPU with CARVE_PERF_TEST=1');
        }

        $source = '';
        $length = 0;
        for ($i = 0; $length < 128 * 1024; $i++) {
            $row = "> - item $i alpha delta eta kappa\n>\n>   > ::: note\n";
            $row .= ">   > para *strong $i* and _em_ text\n>   >\n";
            $row .= ">   > 1. inner alpha delta eta\n>   >\n";
            $row .= ">   >    - deeper `code $i`\n>   > :::\n\n";
            $source .= $row;
            $length += strlen($row);
        }

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
        self::assertLessThanOrEqual(1.18, $samples['data'][1] / $samples['hand'][1]);
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
