<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ALineMissingTheQuoteIsNotAtItsItemMarkerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'carve#5273: verse after a span takes the description-shaped lazy line' => [
                "> - :: t\n>   : ::: |\n>     x\n>     %%%\n: b\n",
                "<blockquote>\n  <ul>\n    <li>\n      <dl>\n        <dt>t</dt>\n        <dd>\n          <div class=\"line-block\">\n            <p>x<br>\n: b</p>\n          </div>\n        </dd>\n      </dl>\n    </li>\n  </ul>\n</blockquote>",
            ],
            'unquoted item still ends at its marker column' => [
                "- :: t\n  : ::: |\n    x\n    %%%\n: b\n",
                "<ul>\n  <li>\n    <dl>\n      <dt>t</dt>\n      <dd>\n        <div class=\"line-block\">\n          <p>x</p>\n        </div>\n      </dd>\n    </dl>\n  </li>\n</ul>\n<p>: b</p>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testDescriptionShapedLazyLine(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
