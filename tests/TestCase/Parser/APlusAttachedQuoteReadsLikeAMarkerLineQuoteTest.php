<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#4871: the quote a marker-line `+` takes reads its next line as the same quote on the marker line does (PART 9 §17 L3).
 */
final class APlusAttachedQuoteReadsLikeAMarkerLineQuoteTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pairs(): iterable
    {
        yield 'item, marker between the columns' => ["- +\n> q\n - x\n", "- > q\n - x\n"];

        yield 'description, marker below the content column' => [":: t\n: +\n> q\n - x\n", ":: t\n: > q\n - x\n"];

        yield 'description, description line at the content column' => [":: t\n: +\n> q\n  : x\n", ":: t\n: > q\n  : x\n"];

        yield 'description, marker at the content column' => [":: t\n: +\n> q\n  - x\n", ":: t\n: > q\n  - x\n"];
    }

    #[DataProvider('pairs')]
    public function testBothSpellingsRenderTheSame(string $plus, string $markerLine): void
    {
        $converter = new CarveConverter();
        self::assertSame($converter->convert($markerLine), $converter->convert($plus));
    }

    public function testATermAtTheDescriptionColumnOpensANestedList(): void
    {
        self::assertStringContainsString(
            "  <dd>\n    <blockquote><p>q</p></blockquote>\n    <dl>\n      <dt>u</dt>\n      <dd>v</dd>\n    </dl>\n  </dd>\n</dl>",
            (new CarveConverter())->convert(":: t\n: +\n> q\n  :: u\n  : v\n"),
        );
    }
}
