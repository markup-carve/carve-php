<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5764: a valid wrapped attribute run on a nested marker line opens no paragraph, so a column-0 line leaves the list.
 */
final class AWrappedAttributeOnANestedMarkerLineOpensNoParagraphTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        $nested = "<ul>\n  <li>p\n    <ul>\n      <li></li>\n    </ul>\n  </li>\n</ul>\n<p>plain</p>";

        yield 'wrapped run' => ["- p\n  - {.x\n     }\nplain\n", $nested];
        yield 'one-line run' => ["- p\n  - {.x}\nplain\n", $nested];
        yield 'stacked markers' => ["- - {.x\n     }\nplain\n", "<ul>\n  <li>\n    <ul>\n      <li></li>\n    </ul>\n  </li>\n</ul>\n<p>plain</p>"];
        yield 'description in an item' => [
            "- :: t\n  : {.x\n     }\nplain\n",
            "<ul>\n  <li>\n    <dl>\n      <dt>t</dt>\n      <dd></dd>\n    </dl>\n  </li>\n</ul>\n<p>plain</p>",
        ];

        yield 'ordered outer item' => ["1. p\n   - {.x\n      }\nplain\n", "<ol>\n  <li>p\n    <ul>\n      <li></li>\n    </ul>\n  </li>\n</ol>\n<p>plain</p>"];
        yield 'quoted list' => [
            "> - p\n>   - {.x\n>      }\n> plain\n",
            "<blockquote>\n  <ul>\n    <li>p\n      <ul>\n        <li></li>\n      </ul>\n    </li>\n  </ul>\n  <p>plain</p>\n</blockquote>",
        ];
    }

    #[DataProvider('documents')]
    public function testTheColumnZeroLineLeavesTheList(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
