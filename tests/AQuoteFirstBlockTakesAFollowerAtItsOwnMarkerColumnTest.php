<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expected HTML is the spec model's (CARVE-P9-067, carve#3421).
 */
final class AQuoteFirstBlockTakesAFollowerAtItsOwnMarkerColumnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'quoted ordered item, stacked quote, follower at the inner quote column (#4209)' => ["> 1. > > +\n>      s\n", "<blockquote>\n  <ol>\n    <li>\n      <blockquote>\n        <blockquote><p>+\ns</p></blockquote>\n      </blockquote>\n    </li>\n  </ol>\n</blockquote>"];
        yield 'quoted bullet item, stacked quote, follower at the inner quote column' => ["> - > > +\n>     s\n", "<blockquote>\n  <ul>\n    <li>\n      <blockquote>\n        <blockquote><p>+\ns</p></blockquote>\n      </blockquote>\n    </li>\n  </ul>\n</blockquote>"];
        yield 'stacked quotes, follower at the inner quote column' => ["> > +\n  s\n", "<blockquote>\n  <blockquote><p>+\ns</p></blockquote>\n</blockquote>"];
        yield 'stacked quotes, follower at an intervening column stays text' => ["> > +\n s\n", "<blockquote>\n  <blockquote><p>+\ns</p></blockquote>\n</blockquote>"];
    }

    #[DataProvider('documents')]
    public function testFollower(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
