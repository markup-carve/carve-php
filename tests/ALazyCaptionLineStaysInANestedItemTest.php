<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `^ ` line below every column folds into the deepest open paragraph when the
 * ownership walk reads it as lazy (PART 1 S4, PART 9 §10 I7): a caption does
 * not interrupt, so it cannot end a nested list either.
 */
class ALazyCaptionLineStaysInANestedItemTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'caption below a nested item' => [
            "- a\n  - b\n^ cap\n",
            "<ul>\n  <li>a\n    <ul>\n      <li>b\n^ cap</li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'caption below an ordered host' => [
            "1. a\n   - b\n^ cap\n",
            "<ol>\n  <li>a\n    <ul>\n      <li>b\n^ cap</li>\n    </ul>\n  </li>\n</ol>",
        ];

        yield 'caption after a second paragraph line' => [
            "- a\n\n  - b\n    c\n^ cap\n",
            "<ul>\n  <li><p>a</p>\n    <ul>\n      <li>b\nc\n^ cap</li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'caption after a refused plus (roundtrip-d1f9fa04915c)' => [
            "- a\n\n  - b\n    +\n^ cap\n     c\n",
            "<ul>\n  <li><p>a</p>\n    <ul>\n      <li>b\n+\n^ cap\nc</li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'caption after a quote in the nested item' => [
            "- a\n  - b\n    > q\n^ cap\n",
            "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <blockquote><p>q\n^ cap</p></blockquote>\n      </li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'caption before a sibling item' => [
            "- a\n  - b\n^ cap\n- s\n",
            "<ul>\n  <li>a\n    <ul>\n      <li>b\n^ cap</li>\n    </ul>\n  </li>\n  <li>s</li>\n</ul>",
        ];

        yield 'caption inside a quoted list' => [
            "> - a\n>   - b\n> ^ cap\n",
            "<blockquote>\n  <ul>\n    <li>a\n      <ul>\n        <li>b\n^ cap</li>\n      </ul>\n    </li>\n  </ul>\n</blockquote>",
        ];
    }

    #[DataProvider('cases')]
    public function testTheCaptionFoldsIntoTheNestedParagraph(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    #[DataProvider('cases')]
    public function testTheFormattedTextKeepsTheTree(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert(CarveConverter::toCarve($source))));
    }
}
