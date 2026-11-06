<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A blank inside a `%%%` span is payload (PART 0 S1), so after an item's quote it does not loosen
 * the item the way a blank between its blocks does (carve-php#4792).
 */
final class ABlankInsideASpanAfterAnItemQuoteKeepsTheItemTightTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'carve-php#4792: column-0 span after an empty quote' => [
                "- a\n  >\n%%%\n\n%%%\n  t\n",
                "<ul>\n  <li>a\n    <blockquote>\n\n    </blockquote>\n    t\n  </li>\n</ul>",
            ],
            'span at the item column after a quote with text' => [
                "- a\n  > q\n  %%%\n\n  %%%\n  t\n",
                "<ul>\n  <li>a\n    <blockquote><p>q</p></blockquote>\n    t\n  </li>\n</ul>",
            ],
            'a blank after the span still loosens the item' => [
                "- a\n  > q\n  %%%\n  %%%\n\n  t\n",
                "<ul>\n  <li><p>a</p>\n    <blockquote><p>q</p></blockquote>\n    <p>t</p>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testTheItemStaysTight(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
