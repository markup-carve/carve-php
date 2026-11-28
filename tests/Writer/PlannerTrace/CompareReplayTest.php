<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer\PlannerTrace;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each carve-js `compareGroup` of the trace inputs (`fixtures/carve-js-compares.jsonl`, written by `js-trace.mjs` with
 * `JS_TRACE_COMPARES=1`), replayed on `Writer\Check\Compare` from the same writer state: the escapes, trials,
 * constructs, retractions, forms, losses and attempts must equal. Records in `compares-held.txt` do not match yet; a
 * held record that matches fails, so the list only shrinks.
 */
final class CompareReplayTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function records(): iterable
    {
        $inputs = PlannerTrace::inputs(__DIR__ . '/fixtures/inputs.jsonl');
        foreach (explode("\n", (string)file_get_contents(__DIR__ . '/fixtures/carve-js-compares.jsonl')) as $line) {
            $record = $line === '' ? null : json_decode($line, true);
            if (!is_array($record) || !is_string($record['input'] ?? null) || !isset($inputs[$record['input']])) {
                continue;
            }
            $id = $record['input'] . '#' . (is_int($record['at'] ?? null) ? $record['at'] : 0);
            $fields = [];
            foreach ($record as $key => $value) {
                $fields[(string)$key] = $value;
            }

            yield $id => [$id, $inputs[$record['input']], $fields];
        }
    }

    /**
     * @param string $id
     * @param string $input
     * @param array<string, mixed> $record
     */
    #[DataProvider('records')]
    public function testCompareMatchesCarveJs(string $id, string $input, array $record): void
    {
        [$expected, $actual] = CompareReplay::run($record, $input);
        if (isset(self::held()[$id])) {
            $this->assertNotSame($expected, $actual, "{$id} now matches carve-js: remove it from compares-held.txt");

            return;
        }
        $this->assertSame($expected, $actual, $id);
    }

    /**
     * @return array<string, true>
     */
    private static function held(): array
    {
        $held = [];
        foreach (explode("\n", (string)file_get_contents(__DIR__ . '/compares-held.txt')) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                $held[$line] = true;
            }
        }

        return $held;
    }
}
