<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5298: a `%%%` span in an item inside a div ends at a line that misses its owner (PART 0 S1)
 * and closes at its exact-width closer below the item column (CARVE-P0-005), so the div's `:::` closes the div.
 */
final class ASpanEndedOrClosedBelowItsItemLeavesTheDivCloserTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        $one = "<aside class=\"admonition note\" aria-label=\"Note\">\n  <ul>\n    <li>i</li>\n  </ul>\n</aside>";
        $nested = "<aside class=\"admonition note\" aria-label=\"Note\">\n  <ul>\n    <li>a\n      <ol>\n        <li></li>\n      </ol>\n"
            . "    </li>\n  </ul>\n</aside>";

        return [
            'unclosed span ended by the closer' => ["::: note\n- i\n  %%%\n  x\n:::\n", $one],
            'marker-line span closed at column 1' => ["::: note\n- a\n  1. %%%\n     n\n %%%\n:::\n", $nested],
            'marker-line span closed at column 0' => ["::: note\n- a\n  1. %%%\n     n\n%%%\n:::\n", $nested],
            'bullet marker-line span' => [
                "::: note\n- a\n  - %%%\n    n\n %%%\n:::\n",
                str_replace(['<ol>', '</ol>'], ['<ul>', '</ul>'], $nested),
            ],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testTheDivClosesAtItsCloser(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
