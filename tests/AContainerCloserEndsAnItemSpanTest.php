<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A div's closer below a list item ends the item's `%%%` span and closes the div, and a span's
 * below-column closer opens no new span (CARVE-P0-005, markup-carve/carve#5298). Expected HTML is the spec model's.
 */
final class AContainerCloserEndsAnItemSpanTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'unclosed span on its own line' => [
            "::: note\n- i\n  %%%\n  x\n:::\n",
            "<aside class=\"admonition note\" aria-label=\"Note\">\n  <ul>\n    <li>i</li>\n  </ul>\n</aside>",
        ];

        yield 'marker-line span closed below the item column' => [
            "::: note\n- a\n  1. %%%\n     n\n %%%\n:::\n",
            "<aside class=\"admonition note\" aria-label=\"Note\">\n  <ul>\n    <li>a\n      <ol>\n        <li></li>\n      </ol>\n    </li>\n  </ul>\n</aside>",
        ];

        yield 'a paragraph after the div' => [
            "::: note\n- i\n  %%%\n  x\n:::\np\n",
            "<aside class=\"admonition note\" aria-label=\"Note\">\n  <ul>\n    <li>i</li>\n  </ul>\n</aside>\n<p>p</p>",
        ];
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
