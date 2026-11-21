<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Conformance;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\Test\Support\Corpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LintClauseTest extends TestCase
{
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
            array_flip(['rules', 'platforms', 'includes', 'extensions', 'headingIds']),
        );
        $actual = Carve::lint($source, $options);
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

        return self::catalogMessage($d->code, $d->message, $entry['messageWhen'] ?? null);
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
