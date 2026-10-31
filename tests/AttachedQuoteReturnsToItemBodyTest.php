<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P9-067: the first block attached at a marker column ends before
 * a following line at the item's content column (markup-carve/carve#4385).
 */
final class AttachedQuoteReturnsToItemBodyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'empty div in quote' => [
            "- +\n> :::\n  x\n",
            "<ul>\n  <li>\n    <blockquote>\n      <div>\n\n      </div>\n    </blockquote>\n    x\n  </li>\n</ul>",
        ];

        yield 'heading in quote' => [
            "1. +\n> # H\n   x\n",
            "<ol>\n  <li>\n    <blockquote>\n      <h1 id=\"H\">H</h1>\n    </blockquote>\n    x\n  </li>\n</ol>",
        ];

        yield 'line short of item column' => [
            "- +\n> :::\n x\n",
            "<ul>\n  <li>\n    <blockquote>\n      <div>\n\n      </div>\n    </blockquote>\n  </li>\n</ul>\n<p>x</p>",
        ];
    }

    #[DataProvider('documents')]
    public function testAttachedQuoteReturnsToItsItem(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
