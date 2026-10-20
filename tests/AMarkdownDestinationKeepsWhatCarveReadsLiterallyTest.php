<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AMarkdownDestinationKeepsWhatCarveReadsLiterallyTest extends TestCase
{
    /**
     * Destination conversion cases.
     *
     * @return iterable<string, array{string, string, list<string>|null}>
     */
    public static function cases(): iterable
    {
        yield 'D1' => ['[a](/x\y) [b](/a`b) [c](/a^b{c}) [d](/a"b) [e](/a<b>)', '[a](/x\y) [b](/a`b) [c](/a^b{c}) [d](/a"b) [e](/a<b>)', array_fill(0, 5, 'markdown-link')];
        yield 'D2' => ['[e](/x\\\\)', '[e](/x\\\\)', ['markdown-link']];
        yield 'D3' => ['[f](</a\\\\(>)', '[f](/a\\\\\()', ['markdown-link']];
        yield 'D4' => ['[g](/a\\\\\\\\b)', '[g](/a\\\\\b)', ['markdown-link']];
        yield 'D5' => ['[a](</x)(a>) [b](</x(a))>) [c](</x((a)>) [d](</x\\\\(a)>)', '[a](/x\)\(a) [b](/x(a)\)) [c](/x\((a)) [d](/x\\\\(a))', array_fill(0, 4, 'markdown-link')];
        yield 'D6' => ['[a](/x(a))', '[a](/x(a))', ['markdown-link']];
        yield 'D7' => ["[a][r]\n\n[r]: /x`y", "[a][r]\n\n[r]: /x`y", ['markdown-reference-link', 'markdown-reference-definition']];
        yield 'D8' => ['![a](/p^q.png)', '![a](/p^q.png)', ['markdown-image']];
        yield 'A1' => ['<https://example.com/\[\>', '[https://example.com/\\\\\[\\\\](https://example.com/\[\\\\)', ['markdown-autolink']];
        yield 'A2' => ['<https://e.com/a\b>', '[https://e.com/a\\\\b](https://e.com/a\b)', ['markdown-autolink']];
        yield 'A3' => ['<https://e.com/a`b>', '[https://e.com/a\`b](https://e.com/a`b)', ['markdown-autolink']];
        yield 'table autolink escaped pipe' => ["| <http://x/a\\\\|b> | z |\n|---|---|", '|= [http://x/a\\\\\\|b](http://x/a\\%7Cb) |= z |', null];
        yield 'angle destination protected escaped angle' => ['[a](<\<>)', '[a](<)', ['markdown-link']];
        yield 'angle destination protected escaped angle within text' => ['[a](<a\<b>)', '[a](a<b)', ['markdown-link']];
        yield 'angle destination backslash newline' => ["[a](<a\\\nb>)", '[a](a\%0Ab)', null];
        yield 'angle destination backslash newline with title' => ["[a](<a\\\nb> \"t\")", '[a](a\%0Ab "t")', null];
        yield 'angle destination backslash newline image' => ["![a](<a\\\nb>)", '![a](a\%0Ab)', null];
        yield 'angle destination backslash newline list' => ["- [a](<a\\\n  b>)", '- [a](a\%0Ab)', null];
        yield 'angle destination backslash newline blockquote' => ["> [a](<a\\\n   b>)", '> [a](a\%0A%20%20%20b)', null];
        yield 'E1' => ['see https://example.com/[a]b here', 'see [https://example.com/[a]b](https://example.com/[a]b) here', ['markdown-bare-autolink']];
        yield 'E2' => ['see https://e.com/ü here', 'see [https://e.com/ü](https://e.com/ü) here', ['markdown-bare-autolink']];
        yield 'E3' => ['www.example.com/a[b]c', '[www.example.com/a[b]c](http://www.example.com/a[b]c)', ['markdown-bare-autolink']];
        yield 'E4' => ['https://e.com/a]b', '[https://e.com/a\]b](https://e.com/a]b)', ['markdown-bare-autolink']];
        yield 'E5' => ['see https://e.com/[x](y) z', 'see [https://e.com/[x]\(y)](https://e.com/[x](y)) z', ['markdown-bare-autolink']];
        yield 'F1' => ['[x <https://e.com/a]b> y](/u)', '[x <https://e.com/a]b> y](/u)', null];
        yield 'control entity' => ['[a](/x&amp;y)', '[a](/x&y)', null];
        yield 'angle destination escaped ampersand entity' => ['[a](<a\&lt;b>)', '[a](a&lt;b)', ['markdown-link']];
        yield 'plain destination escaped ampersand entity' => ['[a](a\&lt;b)', '[a](a&lt;b)', ['markdown-link']];
        yield 'angle destination entity backslash before angle' => ['[a](<a&#92;&lt;b>)', '[a](a\<b)', null];
        yield 'control autolink' => ['<https://e.com/a[b]>', '<https://e.com/a[b]>', ['markdown-autolink']];
        yield 'control Unicode' => ['[a](/ü.png)', '[a](/ü.png)', ['markdown-link']];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tableAutolinkCases(): iterable
    {
        yield 'https' => ["| <https://example.com/a\\|b> | z |\n|---|---|", '|= [https://example.com/a\|b](https://example.com/a%7Cb) |= z |'];
        yield 'http' => ["| <http://x/a\\|b> | z |\n|---|---|", '|= [http://x/a\|b](http://x/a%7Cb) |= z |'];
    }

    /**
     * GFM unescapes the pipe at row level, so the autolink inside the cell is assessed completely.
     */
    #[DataProvider('tableAutolinkCases')]
    public function testATableAutolinkWithAnEscapedPipeIsAssessed(string $source, string $expected): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport($source . "\n");
        self::assertSame($expected . "\n", $result->value);
        self::assertSame(
            ['markdown-table', 'markdown-table-row', 'markdown-table-cell', 'markdown-autolink', 'markdown-table-cell'],
            array_column($result->diagnostics, 'code'),
        );
    }

    /**
     * Check each destination ruling.
     *
     * @param string $source
     * @param string $expected
     * @param list<string>|null $codes
     */
    #[DataProvider('cases')]
    public function testRuling(string $source, string $expected, ?array $codes): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport($source . "\n");
        self::assertSame($expected . "\n", $result->value);
        $rows = $result->diagnostics;
        if ($codes === null) {
            self::assertSame(['fidelity-unverified'], array_column($rows, 'code'));

            return;
        }
        self::assertSame(array_merge(['markdown-paragraph'], $codes), array_column($rows, 'code'));
        foreach ($rows as $row) {
            self::assertSame(str_contains($row->code, 'autolink') || str_contains($row->code, 'reference') ? 'normalized' : 'preserved', $row->fidelity);
            self::assertSame($row->code === 'markdown-reference-definition' ? 'line:3' : 'line:1', $row->path);
        }
    }
}
