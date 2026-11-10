<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5093: the valid attributed block closes the host before the next plus (A02).
 */
final class AnAttachedAttributeRunClosesItsHostBeforeTheNextPlusTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'wrapped item' => [
            "- +\n{.x\n }\n+\n  z\n",
            "<ul>\n  <li></li>\n</ul>\n<p class=\"x\">+\nz</p>",
        ];

        yield 'one-line item' => [
            "- +\n{.x}\n+\n  z\n",
            "<ul>\n  <li></li>\n</ul>\n<p class=\"x\">+\nz</p>",
        ];

        yield 'outer item still receives its marker-column plus' => [
            "- parent\n  - +\n  {.x}\n+\nz\n",
            "<ul>\n  <li>parent\n    <ul>\n      <li></li>\n    </ul>\n    <p class=\"x\">z</p>\n  </li>\n</ul>",
        ];

        yield 'ordinary attached paragraph still keeps the item' => [
            "- +\np\n+\n  z\n",
            "<ul>\n  <li>p\n    z\n  </li>\n</ul>",
        ];
    }

    #[DataProvider('documents')]
    public function testTheNextPlusHasItsOwnOwner(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
