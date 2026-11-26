<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Conformance;

use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Import\HtmlImporter;
use MarkupCarve\Carve\Import\HtmlParser;
use MarkupCarve\Carve\Import\ImportInputError;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\Test\Support\Corpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Every `import` clause case of the pinned spec (conformance/README.md): written source, tree, diagnostics.
 */
final class ImportClauseCasesTest extends TestCase
{
    /**
     * Cases that fail at the pin, by id, with the ticket or lane that owns the fix. A listed case that passes fails
     * the run, so remove it with the fix.
     *
     * @var array<string, string>
     */
    public const KNOWN_FAIL = [
        'CC-1425' => 'markup-carve/carve#6850: Word conditional comments of a dropped note wrapper are reported (C2-M-109)',
        'CC-2180' => 'markup-carve/carve#6795 (carve-php line): import option listTable, span-mark text',
        'CC-2181' => 'markup-carve/carve#6795 (carve-php line): import option listTable, span-mark text',
        'CC-2182' => 'markup-carve/carve#6795 (carve-php line): import option listTable, span-mark text',
        'CC-2183' => 'markup-carve/carve#6795 (carve-php line): import option listTable, span-mark text',
        'CC-2184' => 'markup-carve/carve#6795 (carve-php line): import option listTable, span-mark text',
        'CC-2393' => 'markup-carve/carve#6395 (carve-php): named snippets are not ported',
        'CC-2394' => 'markup-carve/carve#6395 (carve-php): named snippets are not ported',
        'CC-2395' => 'markup-carve/carve#6395 (carve-php): named snippets are not ported',
        'CC-2396' => 'markup-carve/carve#6395 (carve-php): named snippets are not ported',
    ];

    /**
     * Cases the libxml fallback (PHP below 8.4) imports differently, as `ImportCorpusTest::LEGACY_DIFFERS`.
     *
     * @var array<string, string>
     */
    private const LEGACY_DIFFERS = [
        'CC-1446' => 'libxml adds no implied `<head>`, so nothing is reported as dropped',
    ];

    /**
     * @var array<string>
     */
    private const DIAGNOSTIC_FIELDS = ['code', 'severity', 'channel', 'path', 'line', 'column'];

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        $out = [];
        foreach (Corpus::clauseCases() as $id => $case) {
            if (($case['kind'] ?? null) === 'import') {
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
        if (!HtmlParser::html5() && isset(self::LEGACY_DIFFERS[$case['id']])) {
            $this->markTestSkipped(self::LEGACY_DIFFERS[$case['id']]);
        }
        $mismatches = self::mismatches($case, false);
        $owner = self::KNOWN_FAIL[$case['id']] ?? null;
        if ($owner === null) {
            $this->assertSame([], $mismatches, $case['id'] . ': ' . $case['summary']);

            return;
        }
        $this->assertNotSame([], $mismatches, $case['id'] . ' passes now: remove it from KNOWN_FAIL (' . $owner . ')');
    }

    /**
     * The libxml fallback gives the same result, except the entries of `LEGACY_DIFFERS`.
     *
     * @param array<string, mixed> $case
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testLegacyParserGivesTheSameResult(array $case): void
    {
        if (!HtmlParser::html5()) {
            $this->markTestSkipped('only the libxml parser exists on this PHP');
        }
        if (isset(self::KNOWN_FAIL[$case['id']])) {
            $this->assertTrue(true);

            return;
        }
        $mismatches = self::mismatches($case, true);
        if (isset(self::LEGACY_DIFFERS[$case['id']])) {
            $this->assertNotSame([], $mismatches, $case['id'] . ' agrees now: remove it from LEGACY_DIFFERS');

            return;
        }
        $this->assertSame([], $mismatches, $case['id'] . ': ' . $case['summary']);
    }

    public function testKnownFailListNamesImportCasesOfThePin(): void
    {
        $cases = Corpus::clauseCases();
        foreach (self::KNOWN_FAIL as $id => $owner) {
            $this->assertNotSame('', trim($owner), $id . ' names no owner');
            $this->assertSame('import', $cases[$id]['kind'] ?? null, $id);
        }
    }

    /**
     * @param array<string, mixed> $refusal
     * @param \Throwable $error
     *
     * @return array<string>
     */
    private static function refusalMismatches(array $refusal, Throwable $error): array
    {
        $classes = ['CarveOptionError' => OptionError::class, 'ImportInputError' => ImportInputError::class];
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
     * @param array<string, mixed> $case
     * @param bool $legacy
     *
     * @return array<string> What differs from `expected`; empty when the case holds.
     */
    private static function mismatches(array $case, bool $legacy): array
    {
        $expected = $case['expected'];
        try {
            $result = HtmlImporter::import($case['html'], Corpus::caseOptions($case), $legacy);
        } catch (Throwable $error) {
            return isset($expected['refusal'])
                ? self::refusalMismatches($expected['refusal'], $error)
                : ['threw ' . $error::class . ': ' . $error->getMessage()];
        }
        if (isset($expected['refusal'])) {
            return ['no refusal'];
        }
        $out = [];
        if (array_key_exists('carve', $expected) && $expected['carve'] !== $result->source) {
            $out[] = 'carve: expected ' . json_encode($expected['carve']) . ', got ' . json_encode($result->source);
        }
        if (isset($expected['children'])) {
            $children = Corpus::hasPositions($expected['children'])
                ? $result->document['children'] : Corpus::withoutPositions($result->document['children']);
            if (Corpus::sorted($expected['children']) !== Corpus::sorted($children)) {
                $out[] = 'children differ: got ' . json_encode($children);
            }
        }
        if (isset($expected['suppressed']) && $expected['suppressed'] !== $result->suppressedDiagnostics) {
            $out[] = 'suppressed: expected ' . $expected['suppressed'] . ', got ' . $result->suppressedDiagnostics;
        }

        return [...$out, ...self::diagnosticMismatches($expected, $result->diagnostics, $case['html'])];
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $actual
     * @param string $html
     *
     * @return array<string>
     */
    private static function diagnosticMismatches(array $expected, array $actual, string $html): array
    {
        $out = [];
        $listed = $expected['diagnostics'] ?? [];
        $used = [];
        foreach ($listed as $entry) {
            $entry = is_string($entry) ? ['code' => $entry] : $entry;
            $unknown = array_diff(array_keys($entry), self::DIAGNOSTIC_FIELDS);
            if ($unknown) {
                $out[] = 'diagnostic field ' . implode(', ', $unknown) . ' has no runner mapping';

                continue;
            }
            $found = null;
            foreach ($actual as $at => $diagnostic) {
                if (!isset($used[$at]) && self::entryMatches($entry, $diagnostic, $html)) {
                    $found = $at;

                    break;
                }
            }
            if ($found === null) {
                $out[] = 'missing diagnostic ' . json_encode($entry) . ', got ' . json_encode(array_map(
                    static fn (Diagnostic $d): string => $d->code,
                    $actual,
                ));

                continue;
            }
            $used[$found] = true;
        }
        if (($expected['diagnosticsComplete'] ?? false) === true && count($actual) !== count($listed)) {
            $out[] = 'diagnostics: expected ' . count($listed) . ' entries, got '
                . json_encode(array_map(static fn (Diagnostic $d): string => $d->code, $actual));
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
     * @param \MarkupCarve\Carve\Diagnostics\Diagnostic $diagnostic
     * @param string $html
     *
     * @return bool
     */
    private static function entryMatches(array $entry, Diagnostic $diagnostic, string $html): bool
    {
        $lines = explode("\n", substr($html, 0, max(0, $diagnostic->startOffset)));
        $at = ['line' => count($lines), 'column' => mb_strlen($lines[count($lines) - 1]) + 1];
        foreach ($entry as $field => $value) {
            $actual = $at[$field] ?? $diagnostic->{$field};
            if ($actual !== $value) {
                return false;
            }
        }

        return true;
    }
}
