<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `> +` below a quote opened on a description's first line, or on an item's marker line inside a quote,
 * attaches its follower like the top-level twin (markup-carve/carve#3528, corpus 648).
 */
final class AHostedQuotePlusReadsLikeItsTopLevelTwinTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provider(): array
    {
        return [
            'description, nested quote' => [":: t\n: > > q\n  > +\n  > > r\n", "> > q\n> +\n> > r\n"],
            'description, paragraph' => [":: t\n: > > q\n  > +\n  > para\n", "> > q\n> +\n> para\n"],
            'item in a quote, nested quote' => ["> - > > q\n>   > +\n>   > > r\n", "> > q\n> +\n> > r\n"],
            'item in a quote, paragraph' => ["> - > > q\n>   > +\n>   > para\n", "> > q\n> +\n> para\n"],
            'description in a quote' => ["> :: t\n> : > > q\n>   > +\n>   > > r\n", "> > q\n> +\n> > r\n"],
        ];
    }

    #[DataProvider('provider')]
    public function testTheHostedQuoteHoldsWhatTheTwinHolds(string $hosted, string $twin): void
    {
        $flat = static fn (string $html): string => (string)preg_replace('/>\s+</', '><', trim($html));

        $this->assertStringContainsString(
            $flat((new CarveConverter())->convert($twin)),
            $flat((new CarveConverter())->convert($hosted)),
        );
    }
}
