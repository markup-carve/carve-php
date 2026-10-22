<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuoteAndFootnoteFirstBlockTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'quote takes table' => ["> +\n|= a | < |= b |\n", '<blockquote>'];
        yield 'quote takes paragraph' => ["> +\nflush\n", '<blockquote><p>flush</p></blockquote>'];
        yield 'note takes fence' => ["see[^a]\n\n[^a]: +\n```\nx\n```\n", '<pre><code>x'];
        yield 'attached paragraph ends before next quoted line' => ["> +\nflush\n> more\n", "<p>flush</p>\n  <p>more</p>"];
        yield 'nested quote releases line after fence' => ["> > +\n```\nx\n```\nlazy\n", "</blockquote>\n<p>lazy</p>"];
        yield 'quoted continuation keeps plus as text' => ["> +\n> more\n", "<blockquote><p>+\nmore</p></blockquote>"];
        yield 'nested quoted continuation takes the inner form' => ["> > +\n> more\n", "<blockquote>\n  <blockquote><p>more</p></blockquote>\n</blockquote>"];
        yield 'bare quote keeps plus' => ["> +\n", '<blockquote><p>+</p></blockquote>'];
        yield 'quote before blank keeps plus' => ["> +\n\nafter\n", '<blockquote><p>+</p></blockquote>'];
        yield 'bare note keeps plus' => ["see[^a]\n\n[^a]: +\n", '<p>+<a href='];
    }

    #[DataProvider('cases')]
    public function testFirstBlockForm(string $source, string $expected): void
    {
        self::assertStringContainsString($expected, (new CarveConverter())->convert($source));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function itemQuoteCases(): iterable
    {
        yield 'item quote takes paragraph' => [
            "- a\n  > +\n  q\n",
            "<ul>\n  <li>a\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ul>",
        ];

        yield 'nested item quote takes table' => [
            "1. a\n   - b\n     > +\n     | c |\n",
            "<ol>\n  <li>a\n    <ul>\n      <li>b\n        <blockquote>\n          <table>\n            <tbody>\n"
            . "              <tr><td>c</td></tr>\n            </tbody>\n          </table>\n        </blockquote>\n"
            . "      </li>\n    </ul>\n  </li>\n</ol>",
        ];

        yield 'nested item quote takes fence' => [
            "- a\n  - b\n    > +\n    ```\n    x\n    ```\n",
            "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <blockquote>\n          <pre><code>x\n</code></pre>\n"
            . "        </blockquote>\n      </li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'item quote continued by a quoted line keeps plus' => [
            "- a\n  > +\n  > q\n",
            "<ul>\n  <li>a\n    <blockquote><p>+\nq</p></blockquote>\n  </li>\n</ul>",
        ];

        yield 'item quote leaves a column-zero table outside' => [
            "- a\n\n  > +\n| t |\n",
            "<ul>\n  <li>a\n    <blockquote><p>+</p></blockquote>\n  </li>\n</ul>\n<table>\n  <tbody>\n"
            . "    <tr><td>t</td></tr>\n  </tbody>\n</table>",
        ];

        yield 'marker-line quote takes paragraph at its column' => [
            "- > +\n  x\n",
            "<ul>\n  <li>\n    <blockquote><p>x</p></blockquote>\n  </li>\n</ul>",
        ];

        yield 'marker-line quote takes table at its column' => [
            "- > +\n  | c |\n",
            "<ul>\n  <li>\n    <blockquote>\n      <table>\n        <tbody>\n          <tr><td>c</td></tr>\n"
            . "        </tbody>\n      </table>\n    </blockquote>\n  </li>\n</ul>",
        ];
    }

    #[DataProvider('itemQuoteCases')]
    public function testItemQuoteFirstBlockForm(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
