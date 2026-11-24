<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6544, C2-W-088: brackets on both sides of a nested link pair with each other. Where the bare text reads back,
 * each input is written unchanged.
 */
final class NestedLinkBracketPairTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function bare(): array
    {
        return [
            'strong pair around a nested link' => ["[*[[*[x](u)]]](u)\n"],
            'pair around a nested link' => ["[a [[x](u)] b](v)\n"],
            'pair split by an emphasis around a nested link' => ["[/[a [x](u)/ b]](v)\n"],
            'two nested links inside one pair' => ["[[[x](u) [y](w)]](v)\n"],
            'pair around a nested link in a span' => ["[[ [x](u) ]]{.c}\n"],
            'CC-1567' => ["^*[*[[*^*[*[[*^*[*[[*^*[*x](u)^]]](u)^]]](u)^]]](u)\n"],
        ];
    }

    #[DataProvider('bare')]
    public function testBareTextIsKept(string $input): void
    {
        self::assertSame($input, Carve::format($input));
    }

    public function testUnpairedBracketBesideNestedLinkStillEscapes(): void
    {
        self::assertSame("[a \\[ [x](u) b](v)\n", Carve::format("[a \\[ [x](u) b](v)\n"));
    }
}
