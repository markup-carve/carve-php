<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `: ` line at an item's marker column folds into a quoted description's paragraph; only a description
 * the item holds itself ends the item (PART 0 S4, markup-carve/carve-php#4820). Expected HTML is the spec model's.
 */
final class AQuotedDescriptionTakesTheMarkerColumnLineTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        $quoted = "<blockquote>\n      <dl>\n        <dt>t</dt>\n        <dd>d\n: d</dd>\n      </dl>\n    </blockquote>\n";

        yield 'quote on the marker line' => [
            "- > :: t\n  > : d\n: d\n",
            "<ul>\n  <li>\n    " . $quoted . "  </li>\n</ul>",
        ];

        yield 'quote below a plus' => [
            "- +\n  > :: t\n  > : d\n: d\n",
            "<ul>\n  <li>\n    " . $quoted . "  </li>\n</ul>",
        ];

        yield 'loose item' => [
            "- a\n\n  > :: t\n  > : d\n: d\n",
            "<ul>\n  <li><p>a</p>\n    " . $quoted . "  </li>\n</ul>",
        ];

        yield 'unquoted plus block still ends the items' => [
            "- - > +\n    :: t\n    : d\n: d\n",
            "<ul>\n  <li>\n    <ul>\n      <li>\n        <blockquote>\n          <dl>\n            <dt>t</dt>\n            <dd>d</dd>\n"
            . "          </dl>\n        </blockquote>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>: d</p>",
        ];
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
