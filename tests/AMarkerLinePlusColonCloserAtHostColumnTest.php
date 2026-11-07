<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A colon body with a reachable closer after a blank stays the one block a marker-line plus takes (carve#5405).
 */
final class AMarkerLinePlusColonCloserAtHostColumnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'line block verse opener' => [
            "- +\n::: |\n\n  ::: x\n  :::\n",
            "<ul>\n  <li>\n    <div class=\"line-block\">\n      <p>::: x</p>\n    </div>\n  </li>\n</ul>",
        ];

        yield 'div paragraph after blank' => [
            "- +\n::: x\n\n  a\n  :::\n",
            "<ul>\n  <li>\n    <div class=\"x\">\n      <p>a</p>\n    </div>\n  </li>\n</ul>",
        ];

        yield 'nested item line block' => [
            "- a\n  * +\n  ::: |\n\n    ::: x\n    :::\n",
            "<ul>\n  <li>a\n    <ul>\n      <li>\n        <div class=\"line-block\">\n          <p>::: x</p>\n        </div>\n      </li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'quoted item line block' => [
            "> - +\n> ::: |\n> \n>   ::: x\n>   :::\n",
            "<blockquote>\n  <ul>\n    <li>\n      <div class=\"line-block\">\n        <p>::: x</p>\n      </div>\n    </li>\n  </ul>\n</blockquote>",
        ];

        yield 'preblank indented colon lines remain verse' => [
            "- +\n::: |\n  :::\n  :::\n",
            "<ul>\n  <li>\n    <div class=\"line-block\">\n      <p>&nbsp;&nbsp;:::<br>\n&nbsp;&nbsp;:::</p>\n    </div>\n  </li>\n</ul>",
        ];

        yield 'nested div after blank remains attached' => [
            "- +\n::: x\n  a\n\n  ::: y\n  inner\n  :::\n  :::\n",
            "<ul>\n  <li>\n    <div class=\"x\">\n      <p>a</p>\n      <div class=\"y\">\n        <p>inner</p>\n      </div>\n    </div>\n  </li>\n</ul>",
        ];
    }

    #[DataProvider('documents')]
    public function testCloserAtHostColumn(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
