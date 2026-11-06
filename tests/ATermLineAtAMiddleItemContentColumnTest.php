<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A term line at a marker-line sub-list's content column opens its description list in that item,
 * however deep the lead's markers go (PART 0 S2, markup-carve/carve-php#4824). Expected HTML is the spec model's.
 */
final class ATermLineAtAMiddleItemContentColumnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        $middle = static fn (string $inner, string $dl): string => "<ul>\n  <li>\n    <ul>\n      <li>\n        <ul>\n          <li>"
            . $inner . "</li>\n        </ul>\n" . $dl . "      </li>\n    </ul>\n  </li>\n</ul>";

        yield 'three levels' => [
            "- - - a\n    :: u\n",
            $middle('a', "        <dl>\n          <dt>u</dt>\n        </dl>\n"),
        ];

        yield 'with a description' => [
            "- - - a\n    :: u\n    : v\n",
            $middle('a', "        <dl>\n          <dt>u</dt>\n          <dd>v</dd>\n        </dl>\n"),
        ];

        yield 'after a plus attached description list' => [
            "- - - +\n      :: t\n      : d\n    :: u\n",
            $middle(
                "\n            <dl>\n              <dt>t</dt>\n              <dd>d</dd>\n            </dl>\n          ",
                "        <dl>\n          <dt>u</dt>\n        </dl>\n",
            ),
        ];

        yield 'at the outer content column the outer item keeps it' => [
            "- - - a\n  :: u\n",
            "<ul>\n  <li>\n    <ul>\n      <li>\n        <ul>\n          <li>a</li>\n        </ul>\n      </li>\n    </ul>\n"
            . "    <dl>\n      <dt>u</dt>\n    </dl>\n  </li>\n</ul>",
        ];
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
