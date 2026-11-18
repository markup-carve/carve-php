<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Conformance;

use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\Diagnostics\Diagnostic;
use MarkupCarve\Carve2\Html\HtmlRenderer;
use MarkupCarve\Carve2\Json\TreeEncoder;
use MarkupCarve\Carve2\Json\TreeEquality;
use MarkupCarve\Carve2\Json\TreeError;
use MarkupCarve\Carve2\Json\TreeReader;
use MarkupCarve\Carve2\Options;
use MarkupCarve\Carve2\Test\Support\Corpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

final class TypedCoreClauseTest extends TestCase
{
    /**
     * @var array<string, true>
     */
    private const HELD_EXTENSIONS = [
        'CC-455' => true,
        'CC-456' => true,
        'CC-468' => true,
        'CC-1048' => true,
        'CC-1089' => true,
    ];

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        $out = [];
        foreach (Corpus::clauseCases() as $case) {
            if (
                in_array($case['kind'] ?? null, ['parse', 'render', 'tree'], true)
                && !isset(self::HELD_EXTENSIONS[$case['id']])
            ) {
                $out[$case['clauses'][0] . '/' . $case['id']] = [$case];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testC2TypedCoreClause(array $case): void
    {
        $expected = $case['expected'];
        if ($case['kind'] === 'tree') {
            $this->tree($case, $expected);

            return;
        }
        $source = $case['input'] ?? hex2bin(str_replace(' ', '', $case['inputBytes'] ?? ''));
        $options = Corpus::caseOptions($case);
        if (isset($expected['refusal'])) {
            $this->expectException(Throwable::class);
            if ($case['kind'] === 'parse') {
                Carve2::parse($source, $options);
            } elseif (isset($case['tree'])) {
                $node = TreeReader::read(json_encode($case['tree'], JSON_THROW_ON_ERROR), $options);
                HtmlRenderer::render($node, Options::from($options));
            } else {
                Carve2::toHtml($source, $options);
            }

            return;
        }
        if (isset($expected['children'])) {
            $actual = json_decode(Carve2::toJson($source, $options), true, 4096, JSON_THROW_ON_ERROR);
            if (in_array('C2-N-081', $case['clauses'], true)) {
                self::assertTrue(TreeEquality::equal($expected['children'], $actual['children']));
            } else {
                self::assertSame(Corpus::sorted($expected['children']), Corpus::sorted(Corpus::withoutPositions($actual['children'])));
            }
        }
        if (isset($expected['html'])) {
            if (isset($case['tree'])) {
                $node = TreeReader::read(json_encode($case['tree'], JSON_THROW_ON_ERROR), $options);
                self::assertSame($expected['html'], HtmlRenderer::render($node, Options::from($options)));
            } else {
                self::assertSame($expected['html'], Carve2::toHtml($source, $options));
            }
        }
        if (isset($expected['diagnostics']) || isset($expected['notReported'])) {
            if (isset($case['tree'])) {
                $node = TreeReader::read(json_encode($case['tree'], JSON_THROW_ON_ERROR), $options);
                [, $log] = HtmlRenderer::renderWithLog($node, Options::from($options));
                $actual = array_map(
                    static fn (array $entry): Diagnostic => new Diagnostic(
                        $entry['code'],
                        $entry['severity'],
                        $entry['channel'],
                        $entry['message'],
                        $entry['path'] ?? null,
                    ),
                    $log->entries,
                );
            } else {
                $actual = $case['kind'] === 'parse'
                    ? Carve2::parseWithDiagnostics($source, $options)->diagnostics
                    : Carve2::renderWithDiagnostics($source, $options)->diagnostics;
            }
            $this->diagnostics($source, $expected, $actual);
        }
    }

    /**
     * @param array<string, mixed> $case
     * @param array<string, mixed> $expected
     */
    private function tree(array $case, array $expected): void
    {
        $json = $case['json'] ?? json_encode($case['tree'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (isset($expected['refusal'])) {
            $this->expectException(TreeError::class);
            TreeReader::read($json, Corpus::caseOptions($case));

            return;
        }
        $tree = TreeReader::read($json, Corpus::caseOptions($case));
        self::assertNotNull($tree);
        if (isset($expected['tree'])) {
            $actual = json_decode(TreeEncoder::json($tree, null), true, 4096, JSON_THROW_ON_ERROR);
            self::assertSame(Corpus::sorted($expected['tree']), Corpus::sorted($actual));
        }
    }

    /**
     * @param string|null $source
     * @param array<string, mixed> $expected
     * @param array<\MarkupCarve\Carve2\Diagnostics\Diagnostic> $actual
     */
    private function diagnostics(?string $source, array $expected, array $actual): void
    {
        $codes = array_map(static fn (Diagnostic $d): string => $d->code, $actual);
        foreach ($expected['notReported'] ?? [] as $code) {
            self::assertNotContains($code, $codes);
        }
        foreach ($expected['diagnostics'] ?? [] as $item) {
            if (is_string($item)) {
                self::assertContains($item, $codes);

                continue;
            }
            $match = array_filter($actual, static fn (Diagnostic $d): bool => $d->code === $item['code']);
            self::assertNotEmpty($match, $item['code']);
            $found = false;
            foreach ($match as $entry) {
                if ($this->diagnosticMatches($source, $item, $entry)) {
                    $found = true;

                    break;
                }
            }
            self::assertTrue($found, $item['code'] . ' at the stated location and channel');
        }
        if ($expected['diagnosticsComplete'] ?? false) {
            self::assertCount(count($expected['diagnostics']), $actual);
        }
    }

    /**
     * @param string|null $source
     * @param array<string, mixed> $item
     * @param \MarkupCarve\Carve2\Diagnostics\Diagnostic $entry
     */
    private function diagnosticMatches(?string $source, array $item, Diagnostic $entry): bool
    {
        if (isset($item['severity']) && $item['severity'] !== $entry->severity) {
            return false;
        }
        if (isset($item['channel']) && $item['channel'] !== $entry->channel) {
            return false;
        }
        if (!isset($item['line'], $item['column'])) {
            return true;
        }
        if ($entry->startOffset < 0) {
            return false;
        }
        $prefix = substr($source ?? '', 0, $entry->startOffset);
        $line = substr_count($prefix, "\n") + 1;
        $last = strrpos($prefix, "\n");
        $column = strlen($prefix) - ($last === false ? -1 : $last);

        return [$item['line'], $item['column']] === [$line, $column];
    }
}
