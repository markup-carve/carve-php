<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#4878 and carve#4888: with an exact-width closer past the enclosing item or quote, a line that
 * misses the span's nested host ends the span and that host. Only a quote's search stops at a blank.
 */
class ACommentCloserPastTheItemEndsTheNestedHostTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'quote in an item (carve#4878)' => [
                "- a\n  > %%%\n  > n\n  zz\n%%%\n",
                "<ul>\n  <li>a\n    <blockquote>\n\n    </blockquote>\n    zz\n  </li>\n</ul>",
            ],
            'description in an item (carve#4878)' => [
                "- a\n  :: u\n  : %%%\n    n\n  zz\n%%%\n",
                "<ul>\n  <li>a\n    <dl>\n      <dt>u</dt>\n      <dd></dd>\n    </dl>\n    zz\n  </li>\n</ul>",
            ],
            'nested item, blank before the closer' => [
                "- a\n  - %%%\n    n\n  zz\n\n%%%\n",
                "<ul>\n  <li>a\n    <ul>\n      <li></li>\n    </ul>\n    zz\n  </li>\n</ul>",
            ],
            'description in an item, blank before the closer' => [
                "- a\n  :: u\n  : %%%\n    n\n  zz\n\n%%%\n",
                "<ul>\n  <li>a\n    <dl>\n      <dt>u</dt>\n      <dd></dd>\n    </dl>\n    zz\n  </li>\n</ul>",
            ],
            'item in a quote, line at the quote column' => [
                "> - %%%\n>   n\n> zz\n%%%\n",
                "<blockquote>\n  <ul>\n    <li></li>\n  </ul>\n  <p>zz</p>\n</blockquote>",
            ],
            'third-level item, line in the second' => [
                "- a\n  - b\n    - %%%\n      n\n    zz\n\n%%%\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li></li>\n        </ul>\n        zz\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'a quote search stops at a blank (930-4)' => [
                "- a\n  > %%%\n  > n\n  zz\n\n%%%\n",
                "<ul>\n  <li>a\n    <blockquote>\n\n    </blockquote>\n    zz\n  </li>\n</ul>",
            ],
            'quote, closer below it (carve#4888)' => [
                "> %%%\n> n\nzz\n%%%\n",
                "<blockquote>\n\n</blockquote>\n<p>zz</p>",
            ],
            'nested quote, closer below it (carve#4888)' => [
                "> > %%%\n> > n\nzz\n%%%\n",
                "<blockquote>\n  <blockquote>\n\n  </blockquote>\n</blockquote>\n<p>zz</p>",
            ],
            'list in a quote, closer below it (carve#4888)' => [
                "> %%%\n> - a\nzz\n%%%\n",
                "<blockquote>\n\n</blockquote>\n<p>zz</p>",
            ],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheMissedLineEndsTheNestedHost(string $source, string $expected): void
    {
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }
}
