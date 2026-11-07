<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5381, carve#5383: the blank ends the quote and the list its `+`
 * attached, so the `+` spelling reads like the prefixed one.
 */
final class ABlankEndsTheBlockAQuotePlusAttachesTest extends TestCase
{
    public function testAQuoteLineAfterTheBlankOpensAQuoteInTheItem(): void
    {
        $expected = "<ul>\n  <li><p>a</p>\n    <blockquote>\n      <dl>\n        <dt>t</dt>\n        <dd>u</dd>\n      </dl>\n"
            . "    </blockquote>\n    <blockquote><p>r</p></blockquote>\n  </li>\n</ul>";

        self::assertSame($expected, (new CarveConverter())->convert("- a\n  > +\n  :: t\n  : u\n\n    > r\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideSpellings(): array
    {
        return [
            'description list in an item' => ["- a\n  > +\n  :: t\n  : u\n\n    > r\n", "- a\n  > :: t\n  > : u\n\n    > r\n"],
            'list at the document' => ["> +\n- t\n\n  > r\n", "> - t\n\n  > r\n"],
            'description list, heading follower' => ["> +\n:: t\n: u\n\n  # r\n", "> :: t\n> : u\n\n  # r\n"],
            'nested quote' => ["> > +\n> :: t\n> : u\n>\n>   > r\n", "> > :: t\n> > : u\n>\n>   > r\n"],
            'list in an ordered item' => ["1. a\n   > +\n   - t\n\n      > r\n", "1. a\n   > - t\n\n      > r\n"],
            'two blanks' => ["> +\n- t\n\n\n  - r\n", "> - t\n\n\n  - r\n"],
        ];
    }

    #[DataProvider('provideSpellings')]
    public function testThePlusSpellingReadsLikeThePrefixedOne(string $plus, string $prefixed): void
    {
        self::assertSame((new CarveConverter())->convert($prefixed), (new CarveConverter())->convert($plus));
    }
}
