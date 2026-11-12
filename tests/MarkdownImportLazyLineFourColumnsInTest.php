<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line four columns past the container it stays in opens nothing, and a
 * quote marker under an item's column opens a new quote: both import as
 * cmark-gfm 0.29.0.gfm.13 reads them, the way carve-php 0.1.16 wrote them.
 */
class MarkdownImportLazyLineFourColumnsInTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provideShapes(): array
    {
        return [
            'quote under an item column after a quote in the item' => [
                "- > a [x][r]\n > [r]: https://e\n",
                "- > a [x][r]\n\n>\n\n[r]: https://e\n",
                "<ul>\n  <li>\n    <blockquote><p>a <a href=\"https://e\">x</a></p></blockquote>\n  </li>\n</ul>\n<blockquote>\n\n</blockquote>",
            ],
            'quote under an item column inside a quote' => [
                "> 1. > a [x][r]\n>  > [r]: https://e\n",
                "> 1. > a [x][r]\n>\n> >\n\n[r]: https://e\n",
                "<blockquote>\n  <ol>\n    <li>\n      <blockquote><p>a <a href=\"https://e\">x</a></p></blockquote>\n    </li>\n  </ol>\n  <blockquote>\n\n  </blockquote>\n</blockquote>",
            ],
            'quote marker four columns into a wide item' => [
                "> 100. > a b\n>     > c\n",
                "> 100. > a b\n>      > \\> c\n",
                "<blockquote>\n  <ol start=\"100\">\n    <li>\n      <blockquote><p>a b\n&gt; c</p></blockquote>\n    </li>\n  </ol>\n</blockquote>",
            ],
            'bullet four columns into a wide item' => [
                "> 100. a b\n>     - c\n",
                "> 100. a b\n>      \\- c\n",
                "<blockquote>\n  <ol start=\"100\">\n    <li>a b\n- c</li>\n  </ol>\n</blockquote>",
            ],
            'heading four columns into a wide item, two quotes deep' => [
                ">> 100. > a b\n>>     # c\n",
                "> > 100. > a b\n> >      > \\# c\n",
                "<blockquote>\n  <blockquote>\n    <ol start=\"100\">\n      <li>\n        <blockquote><p>a b\n# c</p></blockquote>\n      </li>\n    </ol>\n  </blockquote>\n</blockquote>",
            ],
            'quote marker four columns past the item column' => [
                "- > a b\n      > d\n",
                "- > a b\n  > \\> d\n",
                "<ul>\n  <li>\n    <blockquote><p>a b\n&gt; d</p></blockquote>\n  </li>\n</ul>",
            ],
            'heading four columns past the item column' => [
                "- > a b\n      # d\n",
                "- > a b\n  > \\# d\n",
                "<ul>\n  <li>\n    <blockquote><p>a b\n# d</p></blockquote>\n  </li>\n</ul>",
            ],
            'quote marker after a quote that closed the inner item' => [
                "- 100. > a b\n    > c\n       > d\n",
                "- 100. > a b\n  > c\n  > \\> d\n",
                "<ul>\n  <li>\n    <ol start=\"100\">\n      <li>\n        <blockquote><p>a b</p></blockquote>\n      </li>\n    </ol>\n    <blockquote><p>c\n&gt; d</p></blockquote>\n  </li>\n</ul>",
            ],
            'the same inside a quote' => [
                "> - 100. > a b\n>     > c\n>        > d\n",
                "> - 100. > a b\n>   > c\n>   > \\> d\n",
                "<blockquote>\n  <ul>\n    <li>\n      <ol start=\"100\">\n        <li>\n          <blockquote><p>a b</p></blockquote>\n        </li>\n      </ol>\n      <blockquote><p>c\n&gt; d</p></blockquote>\n    </li>\n  </ul>\n</blockquote>",
            ],
            'quote marker four columns in under a quote paragraph' => [
                "> p q\n    > x\n",
                "> p q\n> \\> x\n",
                "<blockquote><p>p q\n&gt; x</p></blockquote>",
            ],
            'quote marker four columns in under an item in a quote' => [
                "> - p q\n      > x\n",
                "> - p q\n>   \\> x\n",
                "<blockquote>\n  <ul>\n    <li>p q\n&gt; x</li>\n  </ul>\n</blockquote>",
            ],
            'definition four columns in under a quote in an item' => [
                "> - > p q\n    [r]: https://e\n",
                "> - > p q\n>   > \\[r]: [https://e](https://e)\n",
                "<blockquote>\n  <ul>\n    <li>\n      <blockquote><p>p q\n[r]: <a href=\"https://e\">https://e</a></p></blockquote>\n    </li>\n  </ul>\n</blockquote>",
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
