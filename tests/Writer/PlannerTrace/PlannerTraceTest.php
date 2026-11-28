<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer\PlannerTrace;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The repair rounds of the PHP writer, round for round, against carve-js (`fixtures/carve-js.jsonl`, written by
 * `js-trace.mjs` from `fixtures/inputs.jsonl`): the constructs each round reports, the trials it keeps and the escapes
 * it adds. Inputs in `held.txt` do not match yet; a held input that matches fails, so the list only shrinks. Inputs
 * marked `slow` there run only with `PLANNER_TRACE_SLOW=1`.
 */
final class PlannerTraceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, array<mixed>}>
     */
    public static function rows(): iterable
    {
        $inputs = PlannerTrace::inputs(__DIR__ . '/fixtures/inputs.jsonl');
        foreach (explode("\n", (string)file_get_contents(__DIR__ . '/fixtures/carve-js.jsonl')) as $line) {
            $row = $line === '' ? null : json_decode($line, true);
            if (is_array($row) && is_string($row['input'] ?? null) && isset($inputs[$row['input']])) {
                yield $row['input'] => [$row['input'], $inputs[$row['input']], $row];
            }
        }
    }

    /**
     * @param string $id
     * @param string $input
     * @param array<mixed> $expected
     */
    #[DataProvider('rows')]
    public function testRoundsMatchCarveJs(string $id, string $input, array $expected): void
    {
        $held = self::held();
        if (($held[$id] ?? '') === 'slow' && getenv('PLANNER_TRACE_SLOW') !== '1') {
            $this->markTestSkipped('slow input, run with PLANNER_TRACE_SLOW=1');
        }
        $actual = PlannerTrace::php($input);
        $same = self::rounds($actual) === self::rounds($expected);
        if (isset($held[$id])) {
            $this->assertFalse($same, "{$id} now matches carve-js: remove it from held.txt");

            return;
        }
        $this->assertSame(self::rounds($expected), self::rounds($actual), $id);
    }

    /**
     * The compared part of a trace: the output and, per round, the constructs, trials and added escapes.
     *
     * @param array<mixed> $trace
     *
     * @return array<mixed>
     */
    private static function rounds(array $trace): array
    {
        $rounds = [];
        foreach (is_array($trace['rounds'] ?? null) ? $trace['rounds'] : [] as $round) {
            if (is_array($round)) {
                $rounds[] = [$round['constructs'] ?? [], $round['trials'] ?? [], $round['placed'] ?? []];
            }
        }

        return [$trace['output'] ?? null, $rounds];
    }

    /**
     * @return array<string, string> The held inputs, each with its note (`slow` or `held`).
     */
    private static function held(): array
    {
        $held = [];
        foreach (explode("\n", (string)file_get_contents(__DIR__ . '/held.txt')) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            $held[(string)$parts[0]] = (string)($parts[1] ?? 'held');
        }

        return $held;
    }
}
