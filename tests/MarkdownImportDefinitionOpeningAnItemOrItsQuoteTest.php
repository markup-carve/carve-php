<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#6017 and #6018: a reference definition that opens a
 * list item, or a quote inside one, leaves the item as cmark-gfm
 * 0.29.0.gfm.13 reads it.
 */
class MarkdownImportDefinitionOpeningAnItemOrItsQuoteTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provideShapes(): array
    {
        return [
            'definition, blank, two-line paragraph (carve#6017)' => [
                "- [r]: https://e\n\n  a\n  b\n",
                "- a\n  b\n\n[r]: https://e\n",
                "<ul>\n  <li>a\nb</li>\n</ul>",
            ],
            'definition, blank, one-line paragraph' => [
                "- [r]: https://e\n\n  a\n",
                "- a\n\n[r]: https://e\n",
                "<ul>\n  <li>a</li>\n</ul>",
            ],
            'definition, blank, paragraph, sibling item' => [
                "- [r]: https://e\n\n  a\n- c\n",
                "- a\n- c\n\n[r]: https://e\n",
                "<ul>\n  <li>a</li>\n  <li>c</li>\n</ul>",
            ],
            'definition, blank, two paragraphs stay loose' => [
                "- [r]: https://e\n\n  a\n\n  b\n",
                "- a\n\n  b\n\n[r]: https://e\n",
                "<ul>\n  <li><p>a</p>\n    <p>b</p>\n  </li>\n</ul>",
            ],
            'item in a quote' => [
                "> - [r]: https://e\n>\n>   a\n>   b\n",
                "> - a\n>   b\n\n[r]: https://e\n",
                "<blockquote>\n  <ul>\n    <li>a\nb</li>\n  </ul>\n</blockquote>",
            ],
            'quote in an item, definition last (carve#6018)' => [
                "- > a [x][r]\n  >\n  > [r]: https://e\n",
                "- > a [x][r]\n\n[r]: https://e\n",
                "<ul>\n  <li>\n    <blockquote><p>a <a href=\"https://e\">x</a></p></blockquote>\n  </li>\n</ul>",
            ],
            'quote in an item, definition on the marker line' => [
                "- > [r]: https://e\n  >\n  > a [x][r]\n",
                "- > a [x][r]\n\n[r]: https://e\n",
                "<ul>\n  <li>\n    <blockquote><p>a <a href=\"https://e\">x</a></p></blockquote>\n  </li>\n</ul>",
            ],
            'quote in an indented item' => [
                "  - > [r]: https://e\n    >\n    > a [x][r]\n",
                "- > a [x][r]\n\n[r]: https://e\n",
                "<ul>\n  <li>\n    <blockquote><p>a <a href=\"https://e\">x</a></p></blockquote>\n  </li>\n</ul>",
            ],
            'quote in a nested item' => [
                "- - > a [x][r]\n    >\n    > [r]: https://e\n",
                "- - > a [x][r]\n\n[r]: https://e\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <blockquote><p>a <a href=\"https://e\">x</a></p></blockquote>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'quote in an ordered item' => [
                "1. > a [x][r]\n   >\n   > [r]: https://e\n",
                "1. > a [x][r]\n\n[r]: https://e\n",
                "<ol>\n  <li>\n    <blockquote><p>a <a href=\"https://e\">x</a></p></blockquote>\n  </li>\n</ol>",
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testImportsAsCmarkGfmReadsIt(string $markdown, string $carve, string $html): void
    {
        $imported = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame($carve, $imported);
        $this->assertSame($html, trim((new CarveConverter())->convert($imported)));
    }
}
