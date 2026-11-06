<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AStackedMarkerLineSpanEndsBelowItsItemTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'carve-php#4782: sibling of a span opened on stacked markers keeps its lazy line' => [
                "- - %%%\n  - m\nc\n",
                "<ul>\n  <li>\n    <ul>\n      <li></li>\n      <li>m\nc</li>\n    </ul>\n  </li>\n</ul>",
            ],
            'ordered outer marker' => [
                "1. - %%%\n   - m\nc\n",
                "<ol>\n  <li>\n    <ul>\n      <li></li>\n      <li>m\nc</li>\n    </ul>\n  </li>\n</ol>",
            ],
            'three stacked markers' => [
                "- - - %%%\n    - m\nc\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <ul>\n          <li></li>\n          <li>m\nc</li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'a column-zero opener after the ended span opens a new span' => [
                "1. 2) %%%\n    * m\n%%%\ntail\n",
                "<ol>\n  <li>\n    <ol start=\"2\" data-delim=\")\">\n      <li></li>\n    </ol>\n    <ul>\n      <li>m</li>\n    </ul>\n  </li>\n</ol>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testLazyLineFoldsAfterTheSpanEnds(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
