<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownTablePipesTest extends TestCase
{
    #[DataProvider('oracleCases')]
    public function testTableMeaningMatchesCmarkGfm(string $source, string $html): void
    {
        $carve = (new MarkdownToCarve())->convert($source);
        $actual = (new CarveConverter())->convert($carve);
        $actual = preg_replace('/(<h[1-6]) id="[^"]*"/', '$1', $actual) ?? $actual;
        $actual = preg_replace('/\s+scope="(?:col|row)"/', '', $actual) ?? $actual;
        self::assertSame(preg_replace('/>\s+</', '><', trim(str_replace('&quot;', '"', $html))), preg_replace('/>\s+</', '><', trim(str_replace('&quot;', '"', $actual))));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function oracleCases(): iterable
    {
        $cases = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/markdown-table-pipes-gfm.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $index => $case) {
            yield 'cmark-gfm ' . $index => [$case['md'], $case['html']];
        }
    }

    public function testHeaderCodePipeExplainsParagraphFallback(): void
    {
        $source = "| `a|b` | c |\n|---|---|\n| d | e |";
        $result = (new MarkdownToCarve())->convertWithFidelityReport($source);
        self::assertSame(['markdown-table-code-pipe', 'markdown-table-header-mismatch'], array_column(array_slice($result->report()['diagnostics'], 1), 'code'));
        foreach (array_slice($result->diagnostics, 1) as $diagnostic) {
            self::assertSame('line:1', $diagnostic->path);
            self::assertSame('preserved', $diagnostic->fidelity);
            self::assertSame('exact', $diagnostic->confidence);
        }
        self::assertStringContainsString('escape it as \\|', $result->diagnostics[1]->message);
        self::assertStringNotContainsString('<table>', (new CarveConverter())->convert($result->value));
    }

    public function testBodyCodePipeReportsSplitAndOmittedCell(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("| a | c |\n|---|---|\n| `d|x` | e |");
        self::assertSame(['markdown-table-code-pipe', 'markdown-table-extra-cells'], array_column(array_slice($result->report()['diagnostics'], 1), 'code'));
        self::assertSame('line:3', $result->diagnostics[1]->path);
        self::assertSame('dropped', $result->diagnostics[2]->fidelity);
        self::assertStringContainsString('<td>`d</td><td>x`</td>', (new CarveConverter())->convert($result->value));
    }

    public function testSourceLinesSurviveFrontmatterAndMovedDefinitions(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("---\ntitle: example\n---\n[id]: /url\n\n| a | b |\n|---|---|\n| `x|y` | z |");
        self::assertSame('line:8', $result->diagnostics[1]->path);
        self::assertSame('line:8', $result->diagnostics[2]->path);
    }

    public function testListItemTableDiagnosticsNameOriginalLines(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("- | a | b |\n  |---|---|\n  | `x|y` | z |");
        self::assertSame('line:3', $result->diagnostics[1]->path);
        $result = (new MarkdownToCarve())->convertWithFidelityReport("- | `a|b` | c |\n  |---|---|");
        self::assertSame(['markdown-table-code-pipe', 'markdown-table-header-mismatch'], array_column(array_slice($result->report()['diagnostics'], 1), 'code'));
    }

    #[DataProvider('quietCases')]
    public function testUnrelatedPipesDoNotProduceTableWarnings(string $source): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport($source);
        self::assertSame([], array_values(array_filter(array_column($result->diagnostics, 'code'), static fn (string $code): bool => str_starts_with($code, 'markdown-table-'))));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function quietCases(): array
    {
        return [
            'escaped code pipe' => ["| `a\\|b` | c |\n|---|---|\n| d | e |"],
            'unmatched backtick' => ["| `a | c |\n|---|---|\n| d | e |"],
            'plain paragraph' => ['text `a|b` here'],
            'fenced code' => ["```\n| `a|b` | c |\n|---|---|\n```"],
            'indented code' => ["    | `a|b` | c |\n    |---|---|"],
            'comment' => ["| <!-- `a|b` --> | c |\n|---|---|---|"],
            'attribute' => ["| <i title=\"`a|b`\">x</i> | c |\n|---|---|---|"],
        ];
    }

    public function testTablePipeUnescapingPrecedesRawHtmlConversion(): void
    {
        $source = "| <i title=\"a\\|b\">x</i> | c |\n|---|---|\n| <!-- a\\|b --> | d |";
        $html = (new CarveConverter())->convert((new MarkdownToCarve())->convert($source));
        self::assertStringContainsString('<i title="a|b">x</i>', $html);
        self::assertStringContainsString('<!-- a|b -->', $html);
    }

    public function testTableUrlRepairDoesNotChangeBareUrlsInParagraphs(): void
    {
        $source = '(see https://a.com/x|y&amp;z)';
        $carve = (new MarkdownToCarve())->convert($source);
        self::assertStringNotContainsString('<a ', (new CarveConverter())->convert($carve));
        self::assertStringNotContainsString("\x00", $carve);
    }

    public function testHtmlBlockRowsDoNotProduceTableWarnings(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("- <div>\n  | a | b |\n  |---|\n  </div>");
        self::assertCount(1, $result->diagnostics);
    }

    public function testReportsResetBetweenConversions(): void
    {
        $converter = new MarkdownToCarve();
        $converter->convertWithFidelityReport("| a | b |\n|---|---|\n| `x|y` | z |");
        $rows = $converter->convertWithFidelityReport("| a | b |\n|---|---|")->diagnostics;
        self::assertSame(['markdown-table', 'markdown-table-row', 'markdown-table-cell', 'markdown-table-cell'], array_column($rows, 'code'));
    }

    public function testEscapedBackticksUseClosedLiteralSpansInCells(): void
    {
        $carve = (new MarkdownToCarve())->convert("| a | b |\n|---|---|\n| `x|y` | z |");
        self::assertSame("|= a |= b |\n| !`` ` ``{% %}x | y!`` ` ``{% %} |", $carve);
        self::assertStringContainsString('<td>`x</td><td>y`</td>', (new CarveConverter())->convert($carve));
    }

    public function testEmptyOverflowCellsDoNotClaimLostContent(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("| a | b |\n|---|---|\n| c | d | |");
        self::assertCount(1, $result->diagnostics);
    }

    public function testQuotedTableReportsSourceLines(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("> | a | b |\n> |---|---|\n> | `x|y` | z |");
        self::assertSame('line:3', $result->diagnostics[1]->path);
        self::assertStringContainsString('<thead>', (new CarveConverter())->convert($result->value));
        $result = (new MarkdownToCarve())->convertWithFidelityReport("> | `a|b` | c |\n> |---|---|");
        self::assertSame(['markdown-table-code-pipe', 'markdown-table-header-mismatch'], array_column(array_slice($result->report()['diagnostics'], 1), 'code'));
    }

    public function testUnmatchedBacktickRunsHaveLinearScanWork(): void
    {
        $converter = new class extends MarkdownToCarve {
            public int $scannedBackticks = 0;

            protected function backtickRunLength(string $line, int $index): int
            {
                $length = parent::backtickRunLength($line, $index);
                $this->scannedBackticks += $length;

                return $length;
            }

            public function protect(string $source): string
            {
                return $this->protectCodeSpans($source, static fn (string $span): string => $span);
            }
        };
        $source = '';
        for ($size = 1; $size <= 128; $size++) {
            $source .= 'x' . str_repeat('`', $size) . ' ';
        }
        self::assertSame(str_replace('`', '\\`', $source), $converter->protect($source));
        self::assertLessThanOrEqual(2 * strlen($source), $converter->scannedBackticks);
    }

    public function testPartialRunAfterEscapedBacktickStillOpensCode(): void
    {
        $carve = (new MarkdownToCarve())->convert('\\``a`');
        self::assertStringContainsString('<code>a</code>', (new CarveConverter())->convert($carve));
    }
}
