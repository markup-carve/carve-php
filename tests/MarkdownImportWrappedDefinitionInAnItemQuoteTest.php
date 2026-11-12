<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A reference definition whose destination or title wraps onto the next line
 * of a quote inside a list item is read as cmark-gfm 0.29.0.gfm.13 reads it,
 * and a lazy line after it continues the quote.
 */
class MarkdownImportWrappedDefinitionInAnItemQuoteTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provideShapes(): array
    {
        return [
            'destination on the next line, lazy line' => [
                "- > [a]:\n  > /u\nlazy [a]\n",
                "- > lazy [a](/u)\n",
                "<ul>\n  <li>\n    <blockquote><p>lazy <a href=\"/u\">a</a></p></blockquote>\n  </li>\n</ul>",
            ],
            'title on the next line, lazy line at the item column' => [
                "1. > [a]: /u\n   > \"t\"\n   lazy [a]\n",
                "1. > lazy [a](/u \"t\")\n",
                "<ol>\n  <li>\n    <blockquote><p>lazy <a href=\"/u\" title=\"t\">a</a></p></blockquote>\n  </li>\n</ol>",
            ],
            'destination and title on the next line, quoted paragraph' => [
                "- > [a]:\n  > /u \"t\"\n  > b [a]\n",
                "- > b [a](/u \"t\")\n",
                "<ul>\n  <li>\n    <blockquote><p>b <a href=\"/u\" title=\"t\">a</a></p></blockquote>\n  </li>\n</ul>",
            ],
            'indented continuation after a wrapped title' => [
                "- > [a]: /u\n  > \"t\"\n  >     lazy [a]\n",
                "- > lazy [a](/u \"t\")\n",
                "<ul>\n  <li>\n    <blockquote><p>lazy <a href=\"/u\" title=\"t\">a</a></p></blockquote>\n  </li>\n</ul>",
            ],
            'indented continuation after a one-line definition' => [
                "- > [a]: /u\n  >     - x\n",
                "- > \\- x\n\n[a]: /u\n",
                "<ul>\n  <li>\n    <blockquote><p>- x</p></blockquote>\n  </li>\n</ul>",
            ],
            'tab-led quoted line after a definition' => [
                "- > [x]: /u\n\t> b\n",
                "- > b\n\n[x]: /u\n",
                "<ul>\n  <li>\n    <blockquote><p>b</p></blockquote>\n  </li>\n</ul>",
            ],
            'tab-led definition after a definition' => [
                "- > [x]: /u\n\t> [y]: /v\n\n[y]\n",
                "- >\n\n[y][]\n\n[x]: /u\n\n[y]: /v\n",
                "<ul>\n  <li>\n    <blockquote>\n\n    </blockquote>\n  </li>\n</ul>\n<p><a href=\"/v\">y</a></p>",
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
