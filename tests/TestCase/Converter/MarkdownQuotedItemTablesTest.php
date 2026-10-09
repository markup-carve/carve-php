<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownQuotedItemTablesTest extends TestCase
{
    #[DataProvider('oracleCases')]
    public function testTableMeaningMatchesCmarkGfm(string $source, string $html): void
    {
        $carve = (new MarkdownToCarve())->convert($source);
        $actual = (new CarveConverter())->convert($carve);
        $actual = preg_replace('/\n[ \t]+(?=<)/', "\n", $actual) ?? $actual;
        $actual = preg_replace('/(<h[1-6]) id="[^"]*"/', '$1', $actual) ?? $actual;
        $actual = preg_replace('/\s+scope="(?:col|row)"/', '', $actual) ?? $actual;
        // `data-delim` (PART 10 section 12) is outside GFM's vocabulary, like the
        // heading id above: cmark-gfm has no attribute for an ordered
        // delimiter, so the oracle bytes cannot carry one.
        $actual = preg_replace('/(<ol\b[^>]*?) data-delim="[^"]*"/', '$1', $actual) ?? $actual;
        $html = preg_replace('/<(img\b[^>]*?) \/>/', '<$1>', $html) ?? $html;
        self::assertSame(preg_replace('/>\s+</', '><', trim(str_replace('&quot;', '"', $html))), preg_replace('/>\s+</', '><', trim(str_replace('&quot;', '"', $actual))));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function oracleCases(): iterable
    {
        $cases = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/markdown-quoted-item-tables-gfm.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $index => $case) {
            yield 'cmark-gfm ' . $index => [$case['md'], $case['html']];
        }
    }

    public function testQuotedItemTableKeepsDiagnosticSourceLines(): void
    {
        $source = "> - | a | b |\n>   |---|---|\n>   | `x|y` | z |";
        $result = (new MarkdownToCarve())->convertWithFidelityReport($source);
        self::assertSame(['markdown-table-code-pipe', 'markdown-table-extra-cells'], array_column(array_slice($result->report()['diagnostics'], 1), 'code'));
        self::assertSame('line:3', $result->diagnostics[1]->path);
        self::assertSame('line:3', $result->diagnostics[2]->path);
    }
}
