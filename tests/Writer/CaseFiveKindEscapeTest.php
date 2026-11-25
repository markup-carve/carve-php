<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6656 (ruling A), C2-W-088, C2-W-125: a `]` in the text of a bracketed node pairs with a `[` before the node in
 * the bare parse, so the round reports the kind `[` and escapes every potential opener of it in the leaf, beside the
 * case-five backslash on that `]`. Inputs are the forms carve-js wrote before the ruling; outputs are carve-js `next2`.
 */
final class CaseFiveKindEscapeTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function forms(): array
    {
        return [
            'CC-2079, CommonMark 526' => [
                "[foo[https://example.com/?search=\\](uri)](https://example.com/?search=%5D(uri))\n",
                "\\[foo[https://example.com/?search=\\](uri)](https://example.com/?search=%5D(uri))\n",
            ],
            'CC-2080, CommonMark 538' => [
                "[foo[https://example.com/?search=\\][ref]](https://example.com/?search=%5D%5Bref%5D)\n",
                "\\[foo[https://example.com/?search=\\]\\[ref\\]](https://example.com/?search=%5D%5Bref%5D)\n",
            ],
            'a later text bracket of the kind' => ["[foo[x\\]y](u) [x]\n", "\\[foo[x\\]y](u) \\[x]\n"],
            'in a list item' => ["- a [b[x\\]y](u)\n", "- a \\[b[x\\]y](u)\n"],
            'in a table cell' => ["| [foo[a\\]](u) [x] |\n", "| \\[foo[a\\]](u) \\[x] |\n"],
            'an image reports no kind' => ["[foo![a\\]b](u) z\n", "[foo![a\\]b](u) z\n"],
            'a link that holds no `]` keeps the bracket bare' => ["[foo[ab](u)\n", "[foo[ab](u)\n"],
        ];
    }

    #[DataProvider('forms')]
    public function testFormat(string $input, string $expected): void
    {
        $result = Carve::formatWithDiagnostics($input);
        $this->assertSame($expected, $result->source);
        $this->assertSame([], $result->diagnostics);
        $this->assertSame($expected, Carve::format($expected));
        $this->assertSame($expected, Carve::write(Carve::parse($input)));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function markdown(): array
    {
        return [
            'CommonMark 526' => [
                "[foo<https://example.com/?search=](uri)>\n",
                "\\[foo[https://example.com/?search=\\](uri)](https://example.com/?search=%5D(uri))\n",
            ],
            'CommonMark 538' => [
                "[foo<https://example.com/?search=][ref]>\n\n[ref]: /uri\n",
                "\\[foo[https://example.com/?search=\\]\\[ref\\]](https://example.com/?search=%5D%5Bref%5D)\n",
            ],
        ];
    }

    #[DataProvider('markdown')]
    public function testMarkdownImport(string $input, string $expected): void
    {
        $this->assertSame($expected, Carve::importDocument($input, ['from' => 'markdown'])->source);
    }
}
