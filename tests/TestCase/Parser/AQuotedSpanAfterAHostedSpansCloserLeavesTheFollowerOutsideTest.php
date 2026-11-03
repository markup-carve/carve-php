<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A span on a quoted item's marker line is that item's; a follower without `>` misses the quote
 * and ends the quote's own span (PART 0 S1, PART 9 §28; carve#5026, corpus 944).
 */
final class AQuotedSpanAfterAHostedSpansCloserLeavesTheFollowerOutsideTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cases(): array
    {
        return [
            'depth two' => [
                "> > - %%%\n> > %%%\n> > %%%\n> > x\nt\n",
                "<blockquote>\n  <blockquote>\n    <ul>\n      <li></li>\n    </ul>\n  </blockquote>\n</blockquote>\n<p>t</p>",
            ],
            'depth one' => [
                "> - %%%\n> %%%\n> %%%\n> x\nt\n",
                "<blockquote>\n  <ul>\n    <li></li>\n  </ul>\n</blockquote>\n<p>t</p>",
            ],
            'depth three, an ordered item and a list follower' => [
                "> > > 1. %%%%\n> > > %%%%\n> > > %%%%\n> > > x\n- t\n",
                "<blockquote>\n  <blockquote>\n    <blockquote>\n      <ol>\n        <li></li>\n      </ol>\n    </blockquote>\n  </blockquote>\n</blockquote>\n<ul>\n  <li>t</li>\n</ul>",
            ],
            'a nested item, the follower one quote out' => [
                "> > - - %%%\n> >  %%%\n> > %%%\n> > x\n> - t\n",
                "<blockquote>\n  <blockquote>\n    <ul>\n      <li>\n        <ul>\n          <li></li>\n        </ul>\n      </li>\n    </ul>\n  </blockquote>\n  <ul>\n    <li>t</li>\n  </ul>\n</blockquote>",
            ],
            'the closer at the item column' => [
                "> - %%%\n>   %%%\n> %%%\n> x\nt\n",
                "<blockquote>\n  <ul>\n    <li></li>\n  </ul>\n</blockquote>\n<p>t</p>",
            ],
            'a closed quote span folds the follower' => [
                "> - %%%\n> %%%\n> x\nt\n",
                "<blockquote>\n  <ul>\n    <li></li>\n  </ul>\n  <p>x\nt</p>\n</blockquote>",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testTheFollowerReadsOutsideTheQuote(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
