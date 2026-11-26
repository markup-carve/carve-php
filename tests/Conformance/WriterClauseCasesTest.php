<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Conformance;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\Test\Support\Corpus;
use MarkupCarve\Carve\Writer\CarveWriteError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Every `format` and `write` clause case of the pinned spec (conformance/README.md, "Diagnostics", "Layout and writing").
 */
final class WriterClauseCasesTest extends TestCase
{
    /**
     * Cases that fail at the pin, by id, with the ticket or lane that owns the fix. A listed case that passes fails
     * the run, so remove it with the fix. An id above the pin's last case waits for the pin move that brings it.
     *
     * @var array<string, string>
     */
    private const KNOWN_FAIL = [
        'CC-2426' => 'markup-carve/carve#6846: the authored-block fallback of C2-W-088 takes every failing block',
        'CC-2428' => 'markup-carve/carve#6848 (carve-php line): the authored-block fallback of C2-W-088',
        'CC-2429' => 'markup-carve/carve#6848 (carve-php line): the authored-block fallback of C2-W-088',
        'CC-2430' => 'markup-carve/carve#6848 (carve-php line): the authored-block fallback of C2-W-088',
        'CC-2431' => 'markup-carve/carve#6848 (carve-php line): the authored-block fallback of C2-W-088',
    ];

    /**
     * @var array<string>
     */
    private const DIAGNOSTIC_FIELDS = ['code', 'severity', 'channel', 'path', 'file'];

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        $out = [];
        foreach (Corpus::clauseCases() as $id => $case) {
            if (in_array($case['kind'] ?? null, ['format', 'write'], true)) {
                $out[$id] = [$case];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testClauseCase(array $case): void
    {
        $mismatches = self::mismatches($case);
        $owner = self::KNOWN_FAIL[$case['id']] ?? null;
        if ($owner === null) {
            $this->assertSame([], $mismatches, $case['id'] . ': ' . $case['summary']);

            return;
        }
        $this->assertNotSame([], $mismatches, $case['id'] . ' passes now: remove it from KNOWN_FAIL (' . $owner . ')');
    }

    public function testKnownFailListNamesCasesOfThePin(): void
    {
        $cases = Corpus::clauseCases();
        $this->assertNotSame([], self::cases());
        $last = max(array_map(static fn (string $id): int => (int)substr($id, 3), array_keys($cases)));
        foreach (self::KNOWN_FAIL as $id => $owner) {
            $this->assertNotSame('', trim($owner), $id . ' names no owner');
            if (isset($cases[$id])) {
                $this->assertContains($cases[$id]['kind'] ?? null, ['format', 'write'], $id);

                continue;
            }
            $this->assertGreaterThan($last, (int)substr($id, 3), $id . ' is not a case of the pin');
        }
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return array<string> What differs from `expected`; empty when the case holds.
     */
    public static function mismatches(array $case): array
    {
        $expected = $case['expected'];
        $options = $case['options'] ?? [];
        try {
            $result = $case['kind'] === 'format'
                ? Carve::formatWithDiagnostics($case['input'], $options)
                : Carve::writeWithDiagnostics($case['tree'], $case['layout'] ?? null, $options);
        } catch (Throwable $error) {
            if (!isset($expected['refusal'])) {
                return ['threw ' . $error::class . ': ' . $error->getMessage()];
            }

            return self::refusalMismatches($expected['refusal'], $error);
        }
        if (isset($expected['refusal'])) {
            if ($case['kind'] !== 'write') {
                return ['no refusal'];
            }
            // A `write` refusal is a throw of `write`; `writeWithDiagnostics` reports the same losses instead.
            try {
                Carve::write($case['tree'], $case['layout'] ?? null, $options);
            } catch (Throwable $error) {
                return [
                    ...self::refusalMismatches($expected['refusal'], $error),
                    ...self::diagnosticMismatches($expected, $error instanceof CarveWriteError ? $error->diagnostics : []),
                    ...self::diagnosticMismatches($expected, $result->diagnostics),
                ];
            }

            return ['no refusal'];
        }
        $out = [];
        if (array_key_exists('carve', $expected) && $expected['carve'] !== $result->source) {
            $out[] = 'carve: expected ' . json_encode($expected['carve']) . ', got ' . json_encode($result->source);
        }

        return [...$out, ...self::diagnosticMismatches($expected, $result->diagnostics)];
    }

    /**
     * @param array<string, mixed> $refusal
     * @param \Throwable $error
     *
     * @return array<string>
     */
    private static function refusalMismatches(array $refusal, Throwable $error): array
    {
        $classes = ['CarveOptionError' => OptionError::class, 'CarveWriteError' => CarveWriteError::class];
        $class = $classes[$refusal['error']] ?? null;
        if ($class === null) {
            return ['refusal ' . $refusal['error'] . ' has no runner mapping'];
        }
        if (!$error instanceof $class) {
            return ['refusal: expected ' . $refusal['error'] . ', got ' . $error::class . ': ' . $error->getMessage()];
        }
        if (isset($refusal['path']) && (!$error instanceof OptionError || $error->path !== $refusal['path'])) {
            return ['refusal path: expected ' . $refusal['path'] . ', got ' . json_encode($error->path ?? null)];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $actual
     *
     * @return array<string>
     */
    private static function diagnosticMismatches(array $expected, array $actual): array
    {
        $out = [];
        $listed = $expected['diagnostics'] ?? [];
        $ordered = ($expected['diagnosticsOrdered'] ?? false) === true;
        $used = [];
        foreach ($listed as $index => $entry) {
            $entry = is_string($entry) ? ['code' => $entry] : $entry;
            $unknown = array_diff(array_keys($entry), self::DIAGNOSTIC_FIELDS);
            if ($unknown) {
                $out[] = 'diagnostic field ' . implode(', ', $unknown) . ' has no runner mapping';

                continue;
            }
            if ($ordered) {
                if (!isset($actual[$index]) || !self::entryMatches($entry, $actual[$index])) {
                    $out[] = 'diagnostic ' . $index . ': expected ' . json_encode($entry) . ', got '
                        . json_encode(isset($actual[$index]) ? self::summary($actual[$index]) : null);
                }

                continue;
            }
            $found = self::firstUnused($entry, $actual, $used);
            if ($found === null) {
                $out[] = 'missing diagnostic ' . json_encode($entry);

                continue;
            }
            $used[$found] = true;
        }
        if (($expected['diagnosticsComplete'] ?? false) === true && count($actual) !== count($listed)) {
            $out[] = 'diagnostics: expected ' . count($listed) . ' entries, got '
                . json_encode(array_map(self::summary(...), $actual));
        }
        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $actual);
        foreach ($expected['notReported'] ?? [] as $code) {
            if (in_array($code, $codes, true)) {
                $out[] = 'reported ' . $code;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $actual
     * @param array<int, bool> $used
     *
     * @return int|null
     */
    private static function firstUnused(array $entry, array $actual, array $used): ?int
    {
        foreach ($actual as $at => $diagnostic) {
            if (!isset($used[$at]) && self::entryMatches($entry, $diagnostic)) {
                return $at;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     * @param \MarkupCarve\Carve\Diagnostics\Diagnostic $diagnostic
     *
     * @return bool
     */
    private static function entryMatches(array $entry, Diagnostic $diagnostic): bool
    {
        foreach ($entry as $field => $value) {
            if ($diagnostic->{$field} !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param \MarkupCarve\Carve\Diagnostics\Diagnostic $diagnostic
     *
     * @return array<string, string|null>
     */
    private static function summary(Diagnostic $diagnostic): array
    {
        return [
            'code' => $diagnostic->code,
            'severity' => $diagnostic->severity,
            'channel' => $diagnostic->channel,
            'path' => $diagnostic->path,
        ];
    }
}
