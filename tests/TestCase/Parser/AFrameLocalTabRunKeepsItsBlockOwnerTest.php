<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 0 S2 and PART 9 §24 C5: nested readers get the frame-local column the
 * ownership walk used, even when the authored leading run contains a tab.
 */
final class AFrameLocalTabRunKeepsItsBlockOwnerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'quote after a nested item and blank' => [
                "- a\n  - a\n      - a\n    \n\ta\n  \t> z\n",
                "<ul>\n  <li>a\n    <ul>\n      <li><p>a</p>\n        <ul>\n          <li>a</li>\n        </ul>\n        <p>a</p>\n        <blockquote><p>z</p></blockquote>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'heading after a quoted nested item' => [
                "> - a\n>   - a\n> \t- a\n> \t  {.x}\n> \ta\n>    \t# h\n",
                "<blockquote>\n  <ul>\n    <li>a\n      <ul>\n        <li>a\n          <ul>\n            <li>a</li>\n          </ul>\n          a\n          <h1 id=\"h\">h</h1>\n        </li>\n      </ul>\n    </li>\n  </ul>\n</blockquote>",
            ],
            'description beside the innermost quoted term' => [
                "> :: t\n> : a\n>   :: t\n>   : a\n> \t :: t\n> \t: a\n",
                "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>\n      <p>a</p>\n      <dl>\n        <dt>t</dt>\n        <dd>\n          <p>a</p>\n          <dl>\n            <dt>t</dt>\n            <dd>a</dd>\n          </dl>\n        </dd>\n      </dl>\n    </dd>\n  </dl>\n</blockquote>",
            ],
            'past-column description in a quote stays paragraph text' => [
                "> :: a\n> : a\n>   :: t\n>   : a\n>   \t :: t\n>   \t: a\n",
                "<blockquote>\n  <dl>\n    <dt>a</dt>\n    <dd>\n      <p>a</p>\n      <dl>\n        <dt>t</dt>\n        <dd>\n          <p>a</p>\n          <dl>\n            <dt>t</dt>\n          </dl>\n          <p>: a</p>\n        </dd>\n      </dl>\n    </dd>\n  </dl>\n</blockquote>",
            ],
            'same tab run in a description without a quote opens the body' => [
                ":: a\n: a\n  :: t\n  : a\n  \t :: t\n  \t: a\n",
                "<dl>\n  <dt>a</dt>\n  <dd>\n    <p>a</p>\n    <dl>\n      <dt>t</dt>\n      <dd>\n        <p>a</p>\n        <dl>\n          <dt>t</dt>\n          <dd>a</dd>\n        </dl>\n      </dd>\n    </dl>\n  </dd>\n</dl>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testOwnedBlockUsesItsFrameLocalColumn(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
