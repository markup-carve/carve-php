<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AMarkerLineSpanEndsBelowItsItemTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'carve#5274: bullet span ended by an outer paragraph keeps its lazy line' => [
                "- a\n  - %%%\n  h\nc\n",
                "<ul>\n  <li>a\n    <ul>\n      <li></li>\n    </ul>\n    h\nc\n  </li>\n</ul>",
            ],
            'ordered marker span' => [
                "1. a\n   1. %%%\n   h\nc\n",
                "<ol>\n  <li>a\n    <ol>\n      <li></li>\n    </ol>\n    h\nc\n  </li>\n</ol>",
            ],
            'quoted marker span' => [
                "> - a\n>   * %%%%\n>   h\nc\n",
                "<blockquote>\n  <ul>\n    <li>a\n      <ul>\n        <li></li>\n      </ul>\n      h\nc\n    </li>\n  </ul>\n</blockquote>",
            ],
            'description span stays folded' => [
                "- a\n  :: u\n  : %%%\n  h\nc\n",
                "<ul>\n  <li>a\n    <dl>\n      <dt>u</dt>\n      <dd></dd>\n    </dl>\n    h\nc\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testLazyLineFoldsAfterTheSpanEnds(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
