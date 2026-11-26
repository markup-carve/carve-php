<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Conformance;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\Test\Support\Corpus;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LintClauseTest extends TestCase
{
    /**
     * Cases of a ruling this engine has not implemented yet; the run fails as soon as one passes.
     *
     * @var array<string, string>
     */
    private const KNOWN_FAIL = [
        'CC-2241' => 'markup-carve/carve#6663 (carve-php line): header-rows larger than the rows of a pipe table',
        'CC-2247' => 'markup-carve/carve#6664 (carve-php line): an empty body-rows footer count is not clamped',
        'CC-2359' => 'markup-carve/carve#6395 (carve-php): named snippets are not ported',
        'CC-2361' => 'markup-carve/carve#6395 (carve-php): named snippets are not ported',
        'CC-2362' => 'markup-carve/carve#6395 (carve-php): named snippets are not ported',
        'CC-2363' => 'markup-carve/carve#6395 (carve-php): named snippets are not ported',
        'CC-2364' => 'markup-carve/carve#6395 (carve-php): named snippets are not ported',
        'CC-2385' => 'markup-carve/carve#6395 (carve-php): named snippets are not ported',
        'CC-2386' => 'markup-carve/carve#6395 (carve-php): named snippets are not ported',
        'CC-2387' => 'markup-carve/carve#6395 (carve-php): named snippets are not ported',
    ];

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        $out = [];
        foreach (Corpus::clauseCases() as $case) {
            if (($case['kind'] ?? null) === 'lint') {
                $out[$case['id']] = [$case];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testPinnedLintClause(array $case): void
    {
        $owner = self::KNOWN_FAIL[$case['id']] ?? null;
        if ($owner !== null) {
            try {
                $this->check($case);
            } catch (AssertionFailedError) {
                $this->addToAssertionCount(1);

                return;
            }
            $this->fail($case['id'] . ' passes now: remove it from KNOWN_FAIL (' . $owner . ')');
        }
        $this->check($case);
    }

    /**
     * @param array<string, mixed> $case
     */
    private function check(array $case): void
    {
        if (in_array($case['id'], ['CC-813', 'CC-891'], true)) {
            $this->markTestSkipped('The pinned cases disagree on this diagnostic range; see _SPEC_GAPS.md.');
        }
        $source = $case['input'] ?? '';
        $expected = $case['expected'];
        if (isset($expected['refusal'])) {
            try {
                Carve::lint($source, Corpus::caseOptions($case));
                $this->fail('Expected a lint option refusal.');
            } catch (OptionError $error) {
                $this->assertInstanceOf(OptionError::class, $error);
            }

            return;
        }
        $options = array_intersect_key(
            Corpus::caseOptions($case),
            array_flip(['rules', 'platforms', 'upgrade', 'includes', 'extensions', 'headingIds']),
        );
        $actual = Carve::lint($source, $options);
        foreach ($actual as $diagnostic) {
            if ($diagnostic->file !== null) {
                $this->assertNotEmpty($diagnostic->includedBy, $case['id'] . ': ' . $diagnostic->code . ' has no includedBy (C2-T-020)');
            }
        }
        $this->assertDiagnostics($case, $source, $expected, $actual);
    }

    /**
     * @param array<string, mixed> $case
     * @param string $source
     * @param array<string, mixed> $expected
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $actual
     */
    private function assertDiagnostics(array $case, string $source, array $expected, array $actual): void
    {
        $used = [];
        foreach ($expected['diagnostics'] ?? [] as $entry) {
            $entry = is_string($entry) ? ['code' => $entry] : $entry;
            $found = false;
            foreach ($actual as $index => $diagnostic) {
                if (isset($used[$index]) || $diagnostic->code !== $entry['code']) {
                    continue;
                }
                if ($this->matchesExpected($diagnostic, $entry, $case, $source)) {
                    $used[$index] = true;
                    $found = true;

                    break;
                }
            }
            $this->assertTrue($found, $case['id'] . ': missing or mispositioned ' . $entry['code']);
        }
        foreach ($expected['notReported'] ?? [] as $code) {
            $this->assertNotContains($code, array_map(static fn (Diagnostic $d): string => $d->code, $actual));
        }
        if ($expected['diagnosticsComplete'] ?? false) {
            $this->assertCount(count($expected['diagnostics'] ?? []), $actual);
        }
    }

    /**
     * @param \MarkupCarve\Carve\Diagnostics\Diagnostic $d
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $case
     * @param string $source
     */
    private function matchesExpected(Diagnostic $d, array $entry, array $case, string $source): bool
    {
        foreach (['severity', 'channel', 'file', 'path'] as $key) {
            if (isset($entry[$key]) && $d->$key !== $entry[$key]) {
                return false;
            }
        }
        $text = isset($d->file) ? ($case['files'][$d->file]['text'] ?? $source) : $source;
        [$line, $column] = self::place($text, $d->startOffset);
        [$endLine, $endColumn] = self::place($text, $d->endOffset);
        foreach (compact('line', 'column', 'endLine', 'endColumn') as $key => $value) {
            if (isset($entry[$key]) && $entry[$key] !== $value) {
                return false;
            }
        }
        if (isset($entry['message']) && $d->message !== $entry['message']) {
            return false;
        }
        if (isset($entry['includedBy']) && self::includeSites($d, $case, $source) !== $entry['includedBy']) {
            return false;
        }

        return self::catalogMessage($d->code, $d->message, $entry['messageWhen'] ?? null);
    }

    /**
     * C2-T-020: the include chain as a conformance case states it, each site with its file and its line and column.
     *
     * @param \MarkupCarve\Carve\Diagnostics\Diagnostic $d
     * @param array<string, mixed> $case
     * @param string $source
     *
     * @return array<array<string, int|string>>|null
     */
    private static function includeSites(Diagnostic $d, array $case, string $source): ?array
    {
        if ($d->includedBy === null) {
            return null;
        }
        $sites = [];
        foreach ($d->includedBy as $site) {
            $text = isset($site['file']) ? ($case['files'][$site['file']]['text'] ?? '') : $source;
            [$line, $column] = self::place($text, $site['range']['startOffset']);
            $sites[] = (isset($site['file']) ? ['file' => $site['file']] : []) + ['line' => $line, 'column' => $column];
        }

        return $sites;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function place(string $source, int $offset): array
    {
        $prefix = substr($source, 0, max(0, $offset));
        $cut = strrpos($prefix, "\n");

        return [substr_count($prefix, "\n") + 1, mb_strlen(substr($prefix, $cut === false ? 0 : $cut + 1), 'UTF-8') + 1];
    }

    private static function catalogMessage(string $code, string $message, ?string $when): bool
    {
        static $rules = null;
        if ($rules === null) {
            $data = json_decode((string)file_get_contents(__DIR__ . '/../../spec/data/lint-rules.json'), true, 512, JSON_THROW_ON_ERROR);
            $rules = array_column($data['rules'], null, 'id');
        }
        foreach ($rules[$code]['messages'] ?? [] as $variant) {
            $condition = is_array($variant) ? ($variant['when'] ?? null) : null;
            if ($when !== null && $condition !== $when) {
                continue;
            }
            $template = is_array($variant) ? $variant['text'] : $variant;
            $parts = preg_split('/(\$\{[^}]+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
            $pattern = '/^' . implode('', array_map(
                static fn (string $p): string => str_starts_with($p, '${') ? '.*?' : preg_quote($p, '/'),
                $parts,
            )) . '$/us';
            if (preg_match($pattern, $message) === 1 && !str_contains($message, '${')) {
                return true;
            }
        }

        return false;
    }
}
