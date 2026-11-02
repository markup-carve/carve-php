<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * List items from the CommonMark import sweep (markup-carve/carve#4943 and
 * siblings): the expected HTML is commonmark.js 0.31.2's reading of the input.
 */
class MarkdownImportSweepListItemsTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provideImports(): array
    {
        return [
            'a setext underline in an item takes the lazy line above it' => [
                " - foo\nbar\n   ===\n",
                "- # foo bar\n",
                "<ul>\n  <li>\n    <h1 id=\"foo-bar\">foo bar</h1>\n  </li>\n</ul>",
            ],
            'a setext heading in the innermost item of a marker run' => [
                "- - foo\nbar\n    ---\n",
                "- - ## foo bar\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <h2 id=\"foo-bar\">foo bar</h2>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'a closing hash run stays heading text' => [
                "1. foo #\n   ===\n",
                "1. # foo \\#\n",
                "<ol>\n  <li>\n    <h1 id=\"foo\">foo #</h1>\n  </li>\n</ol>",
            ],
            'a trailing backslash stays heading text' => [
                "- foo\\\n  ===\n",
                "- # foo\\\\\n",
                "<ul>\n  <li>\n    <h1 id=\"foo\">foo\\</h1>\n  </li>\n</ul>",
            ],
            'a marker line outside the item is no lazy line' => [
                "- a\n2) foo\n   ===\n",
                "- a\n\n2) # foo\n",
                "<ul>\n  <li>a</li>\n</ul>\n<ol start=\"2\" data-delim=\")\">\n  <li>\n    <h1 id=\"foo\">foo</h1>\n  </li>\n</ol>",
            ],
        ];
    }

    #[DataProvider('provideImports')]
    public function testTheImportReadsTheItemAsCommonMarkDoes(string $markdown, string $carve, string $html): void
    {
        $written = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame($carve, $written);
        $this->assertSame($html, trim((new CarveConverter())->convert($written)));
    }
}
