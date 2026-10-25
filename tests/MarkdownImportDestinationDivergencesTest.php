<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownImportDestinationDivergencesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, list<array{string, string, string}>, int}>
     */
    public static function cases(): iterable
    {
        $paragraph = ['markdown-paragraph', 'preserved', 'line:1'];
        $link = ['markdown-link', 'preserved', 'line:1'];
        $image = ['markdown-image', 'preserved', 'line:1'];
        $autolink = ['markdown-autolink', 'normalized', 'line:1'];
        $bare = ['markdown-bare-autolink', 'normalized', 'line:1'];

        yield 'A1' => ['<https://example.com/\[\>', '[https://example.com/' . str_repeat('\\', 3) . '[' . str_repeat('\\', 2) . '](https://example.com/\[' . str_repeat('\\', 2) . ')', [$paragraph, $autolink], 0];
        yield 'A2' => ['<https://e.com/a\b>', '[https://e.com/a\b](https://e.com/a\b)', [$paragraph, $autolink], 0];
        yield 'A3' => ['<https://e.com/a`b>', '[https://e.com/a\`b](https://e.com/a`b)', [$paragraph, $autolink], 0];
        yield 'A4' => ['<https://e.com/a[b]>', '<https://e.com/a[b]>', [$paragraph, $autolink], 0];
        yield 'B1' => ['[a](<😀>) and ![b](</x y.png>)', '[a](😀) and ![b](/x%20y.png)', [$paragraph, $link, $image], 0];

        foreach (
            [
                'B2' => ['[a](</x>)', '[a](/x)'],
                'B3' => ['[a](<x y>)', '[a](x%20y)'],
                'B4' => ['[a](<x[y]>)', '[a](x[y])'],
                'B5' => ['[a](<x> "t")', '[a](x "t")'],
                'B6' => ['[a](<x)y>)', '[a](x\)y)'],
                'B7' => ['[a](<>)', '[a]()'],
                'B8' => ['[a](<\>>)', '[a](>)'],
                'B9' => ['[a](<x\\y>)', '[a](x\y)'],
                'B10' => ['[a](/x\(y)', '[a](/x\(y)'],
            ] as $name => [$source, $output]
        ) {
            yield $name => [$source, $output, [$paragraph, $link], 0];
        }

        yield 'B11' => ["[a][r]\n\n[r]: <x y>", "[a][r]\n\n[r]: x%20y", [$paragraph, ['markdown-reference-link', 'normalized', 'line:1'], ['markdown-reference-definition', 'normalized', 'line:3']], 0];
        yield 'B entity control' => ['[a](/x&amp;y)', '[a](/x&y)', [['fidelity-unverified', 'dropped', '']], 1];
        yield 'C1' => ['see https://example.com/[a]b here', 'see [https://example.com/[a]b](https://example.com/[a]b) here', [$paragraph, $bare], 0];
        yield 'C2' => ['www.example.com/a[b]c', '[www.example.com/a[b]c](http://www.example.com/a[b]c)', [$paragraph, $bare], 0];
        yield 'C3' => ['https://e.com/a]b', '[https://e.com/a\]b](https://e.com/a]b)', [$paragraph, $bare], 0];
        yield 'C4' => ['https://e.com/a[b', '[https://e.com/a\[b](https://e.com/a[b)', [$paragraph, $bare], 0];
        yield 'C5' => ['see https://e.com/[x](y) z', 'see [https://e.com/[x]\(y)](https://e.com/[x](y)) z', [$paragraph, $bare], 0];
        yield 'C6' => ['https://e.com/a](b)c', '[https://e.com/a\](b)c](https://e.com/a](b)c)', [$paragraph, $bare], 0];
        yield 'C7' => ['https://e.com/[x]{.c}', '[https://e.com/\[x\]{.c}](https://e.com/[x]{.c})', [$paragraph, $bare], 0];
        yield 'C8' => ['https://e.com/a[[b]]', '[https://e.com/a[[b]]](https://e.com/a[[b]])', [$paragraph, $bare], 0];

        $unverified = [['fidelity-unverified', 'dropped', '']];

        yield 'A5 angle form beside a bracket' => ['[foo<https://example.com/?search=](uri)>', '[foo<https://example.com/?search=](uri)>', $unverified, 1];
        yield 'A6 angle form inside a bracket' => ['a [b <https://e.com/]> c', 'a [b <https://e.com/]> c', [$paragraph, $autolink], 0];
        yield 'A7 angle form before a destination shape' => ['<https://e.com/a[b> c](/u)', '<https://e.com/a[b> c](/u)', [$paragraph, $autolink], 0];
    }

    /**
     * @param string $source
     * @param string $expectedOutput
     * @param list<array{string, string, string}> $expectedRows
     * @param int $expectedExit
     */
    #[DataProvider('cases')]
    public function testDestinationOutputAndReport(string $source, string $expectedOutput, array $expectedRows, int $expectedExit): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport($source . "\n");
        self::assertSame($expectedOutput . "\n", $result->value);
        $rows = array_map(static fn ($row): array => [$row->code, $row->fidelity, $row->path ?? ''], $result->diagnostics);
        self::assertSame($expectedRows, $rows);
        foreach ($result->diagnostics as $row) {
            if ($expectedExit === 0) {
                self::assertSame('exact', $row->confidence);
                self::assertSame('info', $row->severity);
            }
        }
        self::assertSame($expectedExit, (int)in_array('fidelity-unverified', array_column($result->diagnostics, 'code'), true));
    }
}
