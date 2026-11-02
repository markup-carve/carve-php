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
            'a bare marker in a quote an item opens is an empty item' => [
                " - >*\n\n   -\n",
                "{loose}\n- > * {empty}\n\n  - {empty}\n",
                "<ul>\n  <li>\n    <blockquote>\n      <ul>\n        <li></li>\n      </ul>\n    </blockquote>\n    <ul>\n      <li></li>\n    </ul>\n  </li>\n</ul>",
            ],
            'a bare marker after an item marker is an empty item' => [
                "- *a\n  - - \n  b*\n",
                "- *a\n  - - {empty}\n  b*\n",
                "<ul>\n  <li>*a\n    <ul>\n      <li>\n        <ul>\n          <li></li>\n        </ul>\n      </li>\n    </ul>\n    b*\n  </li>\n</ul>",
            ],
            'a bare marker after a quoted item marker is an empty item' => [
                "> - -\n",
                "> - - {empty}\n",
                "<blockquote>\n  <ul>\n    <li>\n      <ul>\n        <li></li>\n      </ul>\n    </li>\n  </ul>\n</blockquote>",
            ],
            'a bare marker below an item opens an item, not a lazy line' => [
                "- a\n-\n  ---\n",
                "- a\n- ---\n",
                "<ul>\n  <li>a</li>\n  <li>\n    <hr>\n  </li>\n</ul>",
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
