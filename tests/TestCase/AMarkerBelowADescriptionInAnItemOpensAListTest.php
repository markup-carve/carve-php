<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A marker at an item's column opens a list in that item when a description ends
 * above it (PART 9 §10 I2), also on the path that collects the description after a blank.
 */
class AMarkerBelowADescriptionInAnItemOpensAListTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        $dl = "<dl>\n      <dt>t</dt>\n      <dd>x</dd>\n    </dl>";

        return [
            'after a blank' => [
                "- o\n\n  :: t\n  : x\n  - y\n",
                "<ul>\n  <li><p>o</p>\n    $dl\n    <ul>\n      <li>y</li>\n    </ul>\n  </li>\n</ul>",
            ],
            'ordered marker' => [
                "- o\n\n  :: t\n  : x\n  1. y\n",
                "<ul>\n  <li><p>o</p>\n    $dl\n    <ol>\n      <li>y</li>\n    </ol>\n  </li>\n</ul>",
            ],
            'without a blank, unchanged' => [
                "- o\n  :: t\n  : x\n  - y\n",
                "<ul>\n  <li>o\n    $dl\n    <ul>\n      <li>y</li>\n    </ul>\n  </li>\n</ul>",
            ],
            'plain text stays lazy' => [
                "- o\n\n  :: t\n  : x\n  z\n",
                "<ul>\n  <li><p>o</p>\n    <dl>\n      <dt>t</dt>\n      <dd>x\nz</dd>\n    </dl>\n  </li>\n</ul>",
            ],
            'a quote host still folds' => [
                "- > :: t\n  > : d\n  > - z\n",
                "<ul>\n  <li>\n    <blockquote>\n      <dl>\n        <dt>t</dt>\n        <dd>d</dd>\n      </dl>\n      <ul>\n        <li>z</li>\n      </ul>\n    </blockquote>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheMarkerReading(string $input, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($input));
    }
}
