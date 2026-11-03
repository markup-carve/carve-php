<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `%%%` span on a marker line three or more items deep closes below its column (PART 9 §28, markup-carve/carve#5028).
 * Expected HTML is the spec model's.
 */
final class ASpanOnADeepMarkerLineClosesBelowItsColumnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        $deep = "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li></li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n</ul>";

        yield 'closer at column 0' => ["- a\n  - b\n    - %%%\n%%%\nc\n", $deep . "\n<p>c</p>"];
        yield 'closer at column 1' => ["- a\n  - b\n    - %%%\n %%%\nc\n", $deep . "\n<p>c</p>"];
        yield 'blank in the payload' => ["- a\n  - b\n    - %%%\n      n\n\n%%%\nc\n", $deep . "\n<p>c</p>"];
        yield 'a sibling after the closer' => [
            "- a\n  - b\n    - %%%\n%%%\n- c\n",
            "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <ul>\n          <li></li>\n        </ul>\n      </li>\n    </ul>\n  </li>\n  <li>c</li>\n</ul>",
        ];

        yield 'inside a quote' => ["> - a\n>   - b\n>     - %%%\n> %%%\n> c\n", "<blockquote>\n  " . str_replace("\n", "\n  ", $deep) . "\n  <p>c</p>\n</blockquote>"];
        yield 'a later run after the closer opens a new span' => ["- a\n  - b\n    - %%%\n  %%%\n%%%\nc\n", $deep];
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
