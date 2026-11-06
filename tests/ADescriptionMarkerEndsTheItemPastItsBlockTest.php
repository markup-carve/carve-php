<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `: ` line at an item's marker column ends the item even after a block in its description,
 * so the item collector does not keep it (PART 0 S4, markup-carve/carve#5312). Expected HTML is the spec model's.
 */
final class ADescriptionMarkerEndsTheItemPastItsBlockTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        $list = static fn (string $block): string => "<ul>\n  <li>a\n    <dl>\n      <dt>t</dt>\n      <dd>\n        <p>u</p>\n"
            . $block . "      </dd>\n    </dl>\n  </li>\n</ul>\n";

        yield 'line block' => [
            "- a\n  :: t\n  : u\n    ::: |\n      x\n: b\n",
            $list("        <div class=\"line-block\">\n          <p>&nbsp;&nbsp;x</p>\n        </div>\n") . '<p>: b</p>',
        ];

        yield 'block quote' => [
            "- a\n  :: t\n  : u\n    > x\n: b\n",
            $list("        <blockquote><p>x</p></blockquote>\n") . '<p>: b</p>',
        ];

        yield 'two description lines' => [
            "- a\n  :: t\n  : u\n    ::: |\n      x\n: b\n: c\n",
            $list("        <div class=\"line-block\">\n          <p>&nbsp;&nbsp;x</p>\n        </div>\n") . "<p>: b\n: c</p>",
        ];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nestedDocuments(): iterable
    {
        yield 'a nested item ends at its own marker column' => [
            "- a\n  - a2\n    :: t\n    : u\n      ::: |\n        x\n  : b\n",
            "<ul>\n  <li>a\n    <ul>\n      <li>a2\n        <dl>\n          <dt>t</dt>\n          <dd>\n            <p>u</p>\n"
            . "            <div class=\"line-block\">\n              <p>&nbsp;&nbsp;x</p>\n            </div>\n          </dd>\n        </dl>\n      </li>\n    </ul>\n    : b\n  </li>\n</ul>",
        ];

        yield 'a lazy description-shaped line folds into a div paragraph' => [
            "- a\n  :: t\n  : u\n    ::: note\n    x\n : b\n",
            "<ul>\n  <li>a\n    <dl>\n      <dt>t</dt>\n      <dd>\n        <p>u</p>\n"
            . "        <aside class=\"admonition note\" aria-label=\"Note\">\n          <p>x\n: b</p>\n        </aside>\n      </dd>\n    </dl>\n  </li>\n</ul>",
        ];
    }

    #[DataProvider('nestedDocuments')]
    public function testNestedRender(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
