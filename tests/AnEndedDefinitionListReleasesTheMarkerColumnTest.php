<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The marker-column boundary holds only while the item's definition list is open (PART 0 S4,
 * carve#5352, carve#5368, carve#5373).
 */
final class AnEndedDefinitionListReleasesTheMarkerColumnTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        return [
            'a heading after a term (carve#5352)' => [
                "- a\n  :: t\n  # H\n  w\n: z\n",
                "<ul>\n  <li>a\n    <dl>\n      <dt>t</dt>\n    </dl>\n    <h1 id=\"H\">H</h1>\n    w\n: z\n  </li>\n</ul>",
            ],
            'a paragraph after a term (carve#5368)' => [
                "- a\n  :: t\n  x\n: d\n",
                "<ul>\n  <li>a\n    <dl>\n      <dt>t</dt>\n    </dl>\n    x\n: d\n  </li>\n</ul>",
            ],
            'a paragraph after a quoted term (carve#5368)' => [
                "- a\n  > +\n  :: t\n  x\n: d\n",
                "<ul>\n  <li>a\n    <blockquote>\n      <dl>\n        <dt>t</dt>\n      </dl>\n    </blockquote>\n    x\n: d\n  </li>\n</ul>",
            ],
            'a released colon paragraph after a closed description (carve#5373)' => [
                "- a\n  :: m\n  : d\n\n  p\n\n  : x\n: l\n",
                "<ul>\n  <li><p>a</p>\n    <dl>\n      <dt>m</dt>\n      <dd>d</dd>\n    </dl>\n    <p>p</p>\n    <p>: x\n: l</p>\n  </li>\n</ul>",
            ],
            'a released colon paragraph after a child list (carve#5373)' => [
                "- a\n  :: t\n\n  - n\n  - n\n\n  : u\n: z\n",
                "<ul>\n  <li><p>a</p>\n    <dl>\n      <dt>t</dt>\n    </dl>\n    <ul>\n      <li>n</li>\n      <li>n</li>\n    </ul>\n    <p>: u\n: z</p>\n  </li>\n</ul>",
            ],
            'an open term still ends the item' => [
                "- a\n  :: t\n: d\n",
                "<ul>\n  <li>a\n    <dl>\n      <dt>t</dt>\n    </dl>\n  </li>\n</ul>\n<p>: d</p>",
            ],
            'an open quoted term still ends the item' => [
                "- a\n  > +\n  :: t\n: d\n",
                "<ul>\n  <li>a\n    <blockquote>\n      <dl>\n        <dt>t</dt>\n      </dl>\n    </blockquote>\n  </li>\n</ul>\n<p>: d</p>",
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testMarker(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
