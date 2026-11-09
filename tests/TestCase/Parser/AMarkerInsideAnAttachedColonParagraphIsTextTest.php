<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AMarkerInsideAnAttachedColonParagraphIsTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'marker between the item columns in an open colon paragraph' => [
            "- +\n::: note\nx\n - y\n",
            "<ul>\n  <li>\n    <aside class=\"admonition note\" aria-label=\"Note\">\n      <p>x\n- y</p>\n    </aside>\n  </li>\n</ul>",
        ];

        yield 'stacked host' => [
            "- - +\n  ::: note\n  x\n   - y\n",
            "<ul>\n  <li>\n    <ul>\n      <li>\n        <aside class=\"admonition note\" aria-label=\"Note\">\n          <p>x\n- y</p>\n        </aside>\n      </li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'ordered host' => [
            "1. +\n::: note\nx\n  1. y\n",
            "<ol>\n  <li>\n    <aside class=\"admonition note\" aria-label=\"Note\">\n      <p>x\n1. y</p>\n    </aside>\n  </li>\n</ol>",
        ];

        yield 'line block keeps the marker as verse' => [
            "- +\n::: |\n - y\n",
            "<ul>\n  <li>\n    <div class=\"line-block\">\n      <p>&nbsp;- y</p>\n    </div>\n  </li>\n</ul>",
        ];

        yield 'quoted host' => [
            "> - +\n> ::: note\n> x\n>  - y\n",
            "<blockquote>\n  <ul>\n    <li>\n      <aside class=\"admonition note\" aria-label=\"Note\">\n        <p>x\n- y</p>\n      </aside>\n    </li>\n  </ul>\n</blockquote>",
        ];

        yield 'a marker at the item column still ends the colon block' => [
            "- +\n::: note\nx\n- y\n",
            "<ul>\n  <li>\n    <aside class=\"admonition note\" aria-label=\"Note\">\n      <p>x</p>\n    </aside>\n  </li>\n  <li>y</li>\n</ul>",
        ];

        yield 'a marker with no open paragraph still ends the colon block' => [
            "- +\n::: note\n - y\n",
            "<ul>\n  <li>\n    <aside class=\"admonition note\" aria-label=\"Note\">\n\n    </aside>\n  </li>\n  <li>y</li>\n</ul>",
        ];
    }

    #[DataProvider('cases')]
    public function testTheMarkerStaysInTheAttachedBlock(string $source, string $html): void
    {
        self::assertSame($html, trim((new CarveConverter())->convert($source)));
    }
}
