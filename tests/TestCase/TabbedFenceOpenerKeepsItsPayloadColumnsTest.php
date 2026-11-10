<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A fence opener written on a tab run past nested frames keeps its payload
 * and its closer in the columns its opener was given (markup-carve/carve#5733).
 */
class TabbedFenceOpenerKeepsItsPayloadColumnsTest extends TestCase
{
    #[DataProvider('cases')]
    public function testRendersTheSpecModelHtml(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'quoted list: a bare colon line after a missed line block opens a div' => [
            "> * a\n>   * a\n>     * a\n>   \t::: |\n> \t z\n>   \t:::\n",
            "<blockquote>\n  <ul>\n    <li>a\n      <ul>\n        <li>a\n          <ul>\n            <li>a\n              <div class=\"line-block\">\n\n              </div>\n            </li>\n          </ul>\n          z\n          <div>\n\n          </div>\n        </li>\n      </ul>\n    </li>\n  </ul>\n</blockquote>",
        ];

        yield 'nested items: verse under a two-tab opener' => [
            "- a\n  - a\n\t\t::: |\n  \tz\n\t\t:::\n",
            "<ul>\n  <li>a\n    <ul>\n      <li>a\n        <div class=\"line-block\">\n          <p>z</p>\n        </div>\n      </li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'nested items: spaced verse under a two-tab opener' => [
            "- a\n  - a\n\t\t::: |\n    z\n\t\t:::\n",
            "<ul>\n  <li>a\n    <ul>\n      <li>a\n        <div class=\"line-block\">\n          <p>z</p>\n        </div>\n      </li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'nested items: code under a two-tab opener' => [
            "- a\n  - a\n\t\t```\n  \tz\n\t\t```\n",
            "<ul>\n  <li>a\n    <ul>\n      <li>a\n        <pre><code>z\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'nested descriptions: verse under a two-tab opener' => [
            ":: t\n: a\n  :: t\n  : a\n\t\t::: |\n  \tz\n\t\t:::\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <dl>\n      <dt>t</dt>\n      <dd>\n        <p>a</p>\n        <div class=\"line-block\">\n          <p>z</p>\n        </div>\n      </dd>\n    </dl>\n  </dd>\n</dl>",
        ];

        yield 'ordered in bullet: two verse lines under a tab run' => [
            "- 1. a\n  \t\t::: |\n  \t z\n  \t  y\n  \t\t:::\n",
            "<ul>\n  <li>\n    <ol>\n      <li>a\n        <div class=\"line-block\">\n          <p>z<br>\ny</p>\n        </div>\n      </li>\n    </ol>\n  </li>\n</ul>",
        ];

        yield 'nested items: tabbed code line under a spaced opener' => [
            "- a\n  - a\n      ```\n  \tz\n      ```\n",
            "<ul>\n  <li>a\n    <ul>\n      <li>a\n        <pre><code>z\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>",
        ];
    }
}
