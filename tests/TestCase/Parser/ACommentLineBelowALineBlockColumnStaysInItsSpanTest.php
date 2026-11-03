<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A comment-shaped line below a line block's column inside its open `%%%` span is payload, and an
 * exact-width run there closes it (PART 0 S1, PART 9 §28; carve#4994, carve#5021, corpus 943).
 */
final class ACommentLineBelowALineBlockColumnStaysInItsSpanTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cases(): array
    {
        return [
            'a closer below the column, a blank, then a new span' => [
                "- ::: |\n  %%%\n %%%\n\n  %%%\nz\n",
                "<ul>\n  <li>\n    <div class=\"line-block\">\n\n    </div>\n  </li>\n</ul>\n<p>z</p>",
            ],
            'a longer run at column 0, then a fence below the column' => [
                "- ::: |\n  %%%\n%%%%\n ```\n  %%%\nz\n",
                "<ul>\n  <li>\n    <div class=\"line-block\">\n\n    </div>\n  </li>\n</ul>\n<pre><code> %%%\nz\n</code></pre>",
            ],
            'a longer run below an ordered item, then a sibling list' => [
                "1. ::: |\n   %%%\n  %%%%\n - b\n   %%%\nz\n",
                "<ol>\n  <li>\n    <div class=\"line-block\">\n\n    </div>\n  </li>\n</ol>\n<ul>\n  <li>b</li>\n</ul>\n<p>z</p>",
            ],
            'a nested item, a longer run at column 0' => [
                "- - ::: |\n    %%%\n%%%%\n    %%%\nz\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <div class=\"line-block\">\n\n        </div>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>z</p>",
            ],
            'a nested item under text, a line comment at column 0' => [
                "- x\n  - ::: |\n    %%%\n%% c\n    %%%\nz\n",
                "<ul>\n  <li>x\n    <ul>\n      <li>\n        <div class=\"line-block\">\n\n        </div>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>z</p>",
            ],
            'a run between the outer and the inner column, then verse' => [
                "- - ::: |\n    %%%\n   %%%%\n    %%%\n    b\nz\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <div class=\"line-block\">\n          <p>b<br>\nz</p>\n        </div>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'a run at the outer column, then verse' => [
                "- - ::: |\n    %%%\n  %%%%\n    %%%\n    b\nz\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <div class=\"line-block\">\n          <p>b<br>\nz</p>\n        </div>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'verse before the span, a longer run at column 0' => [
                "- - ::: |\n    a\n    %%%\n%%%%\nz\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <div class=\"line-block\">\n          <p>a<br>\nz</p>\n        </div>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'a description in an item' => [
                "- :: t\n  : ::: |\n    %%%\n%%%%\n    %%%\nz\n",
                "<ul>\n  <li>\n    <dl>\n      <dt>t</dt>\n      <dd>\n        <div class=\"line-block\">\n\n        </div>\n      </dd>\n    </dl>\n  </li>\n</ul>\n<p>z</p>",
            ],
            'an exact-width closer between the columns of a nested item' => [
                "- x\n  - ::: |\n    %%%\n %%%\nz\n",
                "<ul>\n  <li>x\n    <ul>\n      <li>\n        <div class=\"line-block\">\n\n        </div>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>z</p>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testTheLineStaysInTheSpan(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
