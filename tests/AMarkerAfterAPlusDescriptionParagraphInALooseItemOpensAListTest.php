<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#4811: a list marker at a `: +` description's content column ends the pulled paragraph in a loose item.
 */
final class AMarkerAfterAPlusDescriptionParagraphInALooseItemOpensAListTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        return [
            'the ticket reproduction' => [
                "- item\n\n  :: t\n  : +\n  h\n    - a\n",
                "<ul>\n  <li><p>item</p>\n    <dl>\n      <dt>t</dt>\n      <dd>\n        <p>h</p>\n        <ul>\n          <li>a</li>\n        </ul>\n      </dd>\n    </dl>\n  </li>\n</ul>",
            ],
            'past the content column' => [
                "- item\n\n  :: t\n  : +\n  h\n     - a\n",
                "<ul>\n  <li><p>item</p>\n    <dl>\n      <dt>t</dt>\n      <dd>\n        <p>h</p>\n        <ul>\n          <li>a</li>\n        </ul>\n      </dd>\n    </dl>\n  </li>\n</ul>",
            ],
            'an ordered marker in a two-level item' => [
                "- item\n  - item\n\n    :: t\n    : +\n    h\n      1. a\n",
                "<ul>\n  <li>item\n    <ul>\n      <li><p>item</p>\n        <dl>\n          <dt>t</dt>\n          <dd>\n            <p>h</p>\n            <ol>\n              <li>a</li>\n            </ol>\n          </dd>\n        </dl>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'a lazy line after the list' => [
                "- item\n\n  :: t\n  : +\n  h\n    - a\nz\n",
                "<ul>\n  <li><p>item</p>\n    <dl>\n      <dt>t</dt>\n      <dd>\n        <p>h</p>\n        <ul>\n          <li>a\nz</li>\n        </ul>\n      </dd>\n    </dl>\n  </li>\n</ul>",
            ],
            'at the item column the list stays in the item' => [
                "- item\n\n  :: t\n  : +\n  h\n  - a\n",
                "<ul>\n  <li><p>item</p>\n    <dl>\n      <dt>t</dt>\n      <dd>h</dd>\n    </dl>\n    <ul>\n      <li>a</li>\n    </ul>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testMarker(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
