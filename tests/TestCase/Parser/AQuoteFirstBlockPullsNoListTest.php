<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The first-block form pulls only non-list blocks (CARVE-P9-067), so a list
 * item at the quote's column refuses `> +`; the `+` then reads like a letter.
 */
final class AQuoteFirstBlockPullsNoListTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function provider(): array
    {
        return [
            'marker line' => ["- > +\n  - b\n"],
            'nested marker line' => ["- a\n  - > +\n    - b\n"],
            'stacked marker line' => ["- - > +\n    - b\n"],
            'ordered follower' => ["- a\n  - > +\n    1. b\n"],
            'own line in an item' => ["- a\n  > +\n  - b\n"],
        ];
    }

    #[DataProvider('provider')]
    public function testTheRefusedPlusReadsLikeALetter(string $source): void
    {
        $letter = str_replace('> +', '> x', $source);
        $expected = str_replace('<p>x', '<p>+', (new CarveConverter())->convert($letter));

        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testTheListLineFoldsIntoTheQuotedPlus(): void
    {
        $this->assertSame(
            "<ul>\n  <li>\n    <blockquote><p>+\n- b</p></blockquote>\n  </li>\n</ul>",
            trim((new CarveConverter())->convert("- > +\n  - b\n")),
        );
    }
}
