<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ALineBelowAnEndedPlusItemLeavesItTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield "quote at an indented item's marker column" => [
            "  - +\n  > q\n\n  z\n",
            "<ul>\n  <li>\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ul>\n<p>z</p>",
        ];

        yield "fence at an indented item's marker column" => [
            "  - +\n  ```\n  c\n  ```\n\n  z\n",
            "<ul>\n  <li>\n    <pre><code>c\n</code></pre>\n  </li>\n</ul>\n<p>z</p>",
        ];

        yield 'a line one column past the marker column' => [
            "  - +\n  > q\n\n   z\n",
            "<ul>\n  <li>\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ul>\n<p>z</p>",
        ];

        yield 'a line at the content column stays in the item' => [
            "- a\n+\n> q\n\n  x\n",
            "<ul>\n  <li><p>a</p>\n    <blockquote><p>q</p></blockquote>\n    <p>x</p>\n  </li>\n</ul>",
        ];

        yield 'a paragraph attached the same way' => [
            "  - +\n  para\n\n  z\n",
            "<ul>\n  <li>para</li>\n</ul>\n<p>z</p>",
        ];
    }

    #[DataProvider('cases')]
    public function testTheLineLeavesTheItem(string $source, string $html): void
    {
        self::assertSame($html, trim((new CarveConverter())->convert($source)));
    }
}
