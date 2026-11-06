<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line below a nested item's column ends a `%%%` span opened in that item (PART 0 S1) and is
 * text of the outer item; a column-0 line after it is lazy text of that paragraph (S4, carve-php#4791).
 */
final class ALazyLineAfterASpanEndedBelowItsItemTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'carve-php#4791: own-line opener in a nested item' => [
                "* a\n  - y\n    %%%\n  t\ntail\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>y</li>\n    </ul>\n    t\ntail\n  </li>\n</ul>",
            ],
            'opener on a task item marker line' => [
                "* a\n  - [ ] %%%\n  zz\ntail\n",
                "<ul>\n  <li>a\n    <ul class=\"task-list\">\n      <li><input type=\"checkbox\" disabled> </li>\n    </ul>\n    zz\ntail\n  </li>\n</ul>",
            ],
            'opener on stacked markers behind a task box' => [
                "- [ ] * %%%\n  zz\nt\n",
                "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ul>\n      <li></li>\n    </ul>\n    zz\nt\n  </li>\n</ul>",
            ],
            'a quote\'s span left by a wider run does not end the item\'s span' => [
                "* a\n  > %%%\n  %%%%\n  x\nnext\n",
                "<ul>\n  <li>a\n    <blockquote>\n\n    </blockquote>\n  </li>\n</ul>\n<p>next</p>",
            ],
            'a closer below the column still closes the span' => [
                "* a\n  - y\n    %%%\n  %%%\ntail\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>y</li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testTheLazyLineStaysInTheOuterItem(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
