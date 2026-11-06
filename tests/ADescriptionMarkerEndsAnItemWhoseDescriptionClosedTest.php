<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5320: a `: ` line at an item's marker column ends the item once a description in it has closed.
 */
final class ADescriptionMarkerEndsAnItemWhoseDescriptionClosedTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        return [
            'the ticket reproduction' => [
                "- - a\n    :: t\n    : u\n      ::: |\n        x\n  : b\n: c\n",
                "<ul>\n  <li>\n    <ul>\n      <li>a\n        <dl>\n          <dt>t</dt>\n          <dd>\n            <p>u</p>\n            <div class=\"line-block\">\n              <p>&nbsp;&nbsp;x</p>\n            </div>\n          </dd>\n        </dl>\n      </li>\n    </ul>\n    : b\n  </li>\n</ul>\n<p>: c</p>",
            ],
            'a plain description' => [
                "- - a\n    :: t\n    : u\n  : b\n: c\n",
                "<ul>\n  <li>\n    <ul>\n      <li>a\n        <dl>\n          <dt>t</dt>\n          <dd>u</dd>\n        </dl>\n      </li>\n    </ul>\n    : b\n  </li>\n</ul>\n<p>: c</p>",
            ],
            'paragraph text after the first marker line' => [
                "- - a\n    :: t\n    : u\n  : b\n  more\n: c\n",
                "<ul>\n  <li>\n    <ul>\n      <li>a\n        <dl>\n          <dt>t</dt>\n          <dd>u</dd>\n        </dl>\n      </li>\n    </ul>\n    : b\nmore\n  </li>\n</ul>\n<p>: c</p>",
            ],
            'an ordered outer item' => [
                "1. - a\n     :: t\n     : u\n   : b\n: c\n",
                "<ol>\n  <li>\n    <ul>\n      <li>a\n        <dl>\n          <dt>t</dt>\n          <dd>u</dd>\n        </dl>\n      </li>\n    </ul>\n    : b\n  </li>\n</ol>\n<p>: c</p>",
            ],
            'a description directly in the item' => [
                "- o\n  :: t\n  : u\n\n  para\n: c\n",
                "<ul>\n  <li><p>o</p>\n    <dl>\n      <dt>t</dt>\n      <dd>u</dd>\n    </dl>\n    <p>para</p>\n  </li>\n</ul>\n<p>: c</p>",
            ],
            'three levels end in turn' => [
                "- - - a\n      :: t\n      : u\n    : b\n  : c\n: d\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <ul>\n          <li>a\n            <dl>\n              <dt>t</dt>\n              <dd>u</dd>\n            </dl>\n          </li>\n        </ul>\n        : b\n      </li>\n    </ul>\n    : c\n  </li>\n</ul>\n<p>: d</p>",
            ],
            'a new item after the description keeps the lazy line' => [
                "- o\n  - a\n    :: t\n    : u\n  - b\n: c\n",
                "<ul>\n  <li>o\n    <ul>\n      <li>a\n        <dl>\n          <dt>t</dt>\n          <dd>u</dd>\n        </dl>\n      </li>\n      <li>b\n: c</li>\n    </ul>\n  </li>\n</ul>",
            ],
            'no description, no boundary' => [
                "- o\n  : b\n: c\n",
                "<ul>\n  <li>o\n: b\n: c</li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testMarker(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
