<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommentSpanBlankOwnershipFollowUpsTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'carve#5267: description stanza survives a span blank' => [
                ":: t\n: ::: |\n  x\n  %%%\n  \nb\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <div class=\"line-block\">\n      <p>x<br>\nb</p>\n    </div>\n  </dd>\n</dl>",
            ],
            'carve#5269: quoted item releases the next line to its quote' => [
                "> - a\n>   - ::: |\n>     x\n>     %%%\n>     \nb\n",
                "<blockquote>\n  <ul>\n    <li>a\n      <ul>\n        <li>\n          <div class=\"line-block\">\n            <p>x</p>\n          </div>\n        </li>\n      </ul>\n    </li>\n  </ul>\n  <p>b</p>\n</blockquote>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testSpanBlankOwnership(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
