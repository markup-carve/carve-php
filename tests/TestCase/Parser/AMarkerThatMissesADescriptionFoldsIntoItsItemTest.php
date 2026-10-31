<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A list marker that misses a description body folds into the open paragraph
 * of the body's item (PART 0 S2, S4, CARVE-P0-024; markup-carve/carve#4479,
 * #4231). It opens a list only where the line reaches a list item.
 */
class AMarkerThatMissesADescriptionFoldsIntoItsItemTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'column 0' => [":: t\n: - a\n- b\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a\n- b</li>\n    </ul>\n  </dd>\n</dl>"],
            'ordered' => [":: t\n: 1. a\n2. b\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ol>\n      <li>a\n2. b</li>\n    </ol>\n  </dd>\n</dl>"],
            'two lines' => [":: t\n: - a\n- b\n- c\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a\n- b\n- c</li>\n    </ul>\n  </dd>\n</dl>"],
            'quoted' => ["> :: t\n> : - a\n> - b\n", "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>\n      <ul>\n        <li>a\n- b</li>\n      </ul>\n    </dd>\n  </dl>\n</blockquote>"],
            'a blank still ends the description' => [":: t\n: - a\n\n- b\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a</li>\n    </ul>\n  </dd>\n</dl>\n<ul>\n  <li>b</li>\n</ul>"],
            'quoted description in an item' => ["- > :: t\n  > : d\n  > - z\n", "<ul>\n  <li>\n    <blockquote>\n      <dl>\n        <dt>t</dt>\n        <dd>d\n- z</dd>\n      </dl>\n    </blockquote>\n  </li>\n</ul>"],
            'an outer item takes the marker' => ["- :: t\n  : - a\n   - b\n", "<ul>\n  <li>\n    <dl>\n      <dt>t</dt>\n      <dd>\n        <ul>\n          <li>a</li>\n        </ul>\n      </dd>\n    </dl>\n    <ul>\n      <li>b</li>\n    </ul>\n  </li>\n</ul>"],
        ];
    }

    #[DataProvider('cases')]
    public function testMarker(string $source, string $html): void
    {
        $this->assertSame($html, trim((new CarveConverter())->convert($source)));
    }
}
