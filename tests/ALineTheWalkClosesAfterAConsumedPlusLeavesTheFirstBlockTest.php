<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A consumed `+` after the block a first-block `+` attached leaves no paragraph a line off the marker column continues (carve-php#4581). Expected HTML is the spec model's.
 */
final class ALineTheWalkClosesAfterAConsumedPlusLeavesTheFirstBlockTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'line at column 1' => ["- +\nfirst\n+\n x\n", "<ul>\n  <li>first</li>\n</ul>\n<p>x</p>"];
        yield 'ordered item, column 2' => ["1. +\nfirst\n+\n  x\n", "<ol>\n  <li>first</li>\n</ol>\n<p>x</p>"];
        yield 'skipped definition span between' => ["- +\nfirst\n+\n%%%\n[r]: /opaque\n%%%\n [r\\]]: /url\n", "<ul>\n  <li>first</li>\n</ul>\n<p>[r]]: /url</p>"];
        yield 'at the marker column the line still continues' => ["- +\nfirst\n+\nx\n", "<ul>\n  <li>first\n    x\n  </li>\n</ul>"];
    }

    #[DataProvider('documents')]
    public function testTheLineLeavesTheItem(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
