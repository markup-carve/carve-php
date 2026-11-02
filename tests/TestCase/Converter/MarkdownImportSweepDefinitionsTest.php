<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Link reference definitions from the CommonMark import sweep
 * (markup-carve/carve#4923): the expected HTML is commonmark.js 0.31.2's
 * reading of the input.
 */
class MarkdownImportSweepDefinitionsTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provideImports(): array
    {
        return [
            'a label wraps onto the next quoted line' => [
                "> [foo\n> bar]: /url\n>\n> [foo bar]\n",
                "> [foo bar](/url)\n",
                '<blockquote><p><a href="/url">foo bar</a></p></blockquote>',
            ],
            'a destination on a lazy line' => [
                "> [Rust]:\nhttps://rust-lang.org\n\n[Rust]\n",
                ">\n\n[Rust](https://rust-lang.org)\n",
                "<blockquote>\n\n</blockquote>\n<p><a href=\"https://rust-lang.org\">Rust</a></p>",
            ],
            'an indented line under a definition continues its paragraph' => [
                "[l]: a\n    - b\n",
                "\\- b\n\n[l]: a\n",
                '<p>- b</p>',
            ],
            'a lazy line under a nested quoted definition stays in the nested quote' => [
                "> > [foo]: /url\n> \"t\" x\n\n[foo]\n",
                "> > \\\"t\\\" x\n\n[foo][]\n\n[foo]: /url\n",
                "<blockquote>\n  <blockquote><p>\"t\" x</p></blockquote>\n</blockquote>\n<p><a href=\"/url\">foo</a></p>",
            ],
        ];
    }

    #[DataProvider('provideImports')]
    public function testTheImportReadsTheDefinitionAsCommonMarkDoes(string $markdown, string $carve, string $html): void
    {
        $written = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame($carve, $written);
        $this->assertSame($html, trim((new CarveConverter())->convert($written)));
    }
}
