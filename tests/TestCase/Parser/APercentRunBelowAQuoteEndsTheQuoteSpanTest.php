<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `%%%` run below a quote misses the quote, so it ends the quote's open span (PART 0 S1) and
 * opens its own instead of staying payload; the list continues past it (carve-php#4798).
 */
final class APercentRunBelowAQuoteEndsTheQuoteSpanTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'carve-php#4798: the next marker continues the list' => [
                "- a\n  > %%%\n%%%%\n%%%%\n  - s\n",
                "<ul>\n  <li>a\n    <blockquote>\n\n    </blockquote>\n  </li>\n  <li>s</li>\n</ul>",
            ],
            'quoted item span and a lazy line' => [
                "- a\n  > - %%%\n %%%%%\n %%%%%\n  - s\ntail\n",
                "<ul>\n  <li>a\n    <blockquote>\n      <ul>\n        <li></li>\n      </ul>\n    </blockquote>\n  </li>\n  <li>s\ntail</li>\n</ul>",
            ],
            'a comment line below the quote still leaves the list' => [
                "* a\n  > %%%\n  > n\n%% c\nt\n",
                "<ul>\n  <li>a\n    <blockquote>\n\n    </blockquote>\n  </li>\n</ul>\n<p>t</p>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testTheListContinues(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
