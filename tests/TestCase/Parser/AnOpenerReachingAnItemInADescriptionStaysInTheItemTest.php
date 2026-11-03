<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An opener that misses a sublist's column but reaches the item holding it
 * belongs to that item, inside a description body as anywhere else (CARVE-P2-017).
 */
class AnOpenerReachingAnItemInADescriptionStaysInTheItemTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shapes(): iterable
    {
        yield 'heading after the sublist' => [
            ":: t\n: d\n  - a\n    * b\n     # h\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d</p>\n    <ul>\n      <li>a\n        <ul>\n          <li>b</li>\n        </ul>\n        <h1 id=\"h\">h</h1>\n      </li>\n    </ul>\n  </dd>\n</dl>",
        ];

        yield 'term at the outer item column' => [
            ":: t\n: d\n  - a\n    * b\n    :: u\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d</p>\n    <ul>\n      <li>a\n        <ul>\n          <li>b</li>\n        </ul>\n        <dl>\n          <dt>u</dt>\n        </dl>\n      </li>\n    </ul>\n  </dd>\n</dl>",
        ];

        yield 'term between the two columns' => [
            ":: t\n: d\n  - a\n    * b\n     :: u\n     :  e\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d</p>\n    <ul>\n      <li>a\n        <ul>\n          <li>b</li>\n        </ul>\n        <dl>\n          <dt>u</dt>\n          <dd>e</dd>\n        </dl>\n      </li>\n    </ul>\n  </dd>\n</dl>",
        ];

        yield 'quote after a nested item' => [
            ":: t\n: d\n  - a\n    - b\n    > q\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d</p>\n    <ul>\n      <li>a\n        <ul>\n          <li>b</li>\n        </ul>\n        <blockquote><p>q</p></blockquote>\n      </li>\n    </ul>\n  </dd>\n</dl>",
        ];

        yield 'marker-lead description' => [
            ":: t\n: - a\n    * b\n     # h\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a\n        <ul>\n          <li>b</li>\n        </ul>\n        <h1 id=\"h\">h</h1>\n      </li>\n    </ul>\n  </dd>\n</dl>",
        ];

        yield 'below the outer item stays in the body' => [
            ":: t\n: d\n  - a\n    * b\n  # h\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>d</p>\n    <ul>\n      <li>a\n        <ul>\n          <li>b</li>\n        </ul>\n      </li>\n    </ul>\n    <h1 id=\"h\">h</h1>\n  </dd>\n</dl>",
        ];
    }

    #[DataProvider('shapes')]
    public function testRendersLikeTheSpecModel(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
