<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A paragraph a lone + attaches to an indented quote is read from the quote's marker column (CARVE-P0-024). Expected HTML is the spec model's.
 */
final class APlusAtAnIndentedQuotesMarkerColumnRebasesItsParagraphTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'reproduction' => ["  > - a\n  +\n  para\n", "<blockquote>\n  <ul>\n    <li>a</li>\n  </ul>\n  <p>para</p>\n</blockquote>"];
        yield 'three columns in' => ["   > - a\n   +\n   para\n", "<blockquote>\n  <ul>\n    <li>a</li>\n  </ul>\n  <p>para</p>\n</blockquote>"];
        yield 'lazy line after the attached paragraph' => ["  > - a\n  +\n  para\n  more\n", "<blockquote>\n  <ul>\n    <li>a</li>\n  </ul>\n  <p>para\nmore</p>\n</blockquote>"];
        yield 'nested quote' => ["  > > - a\n  +\n  para\n", "<blockquote>\n  <blockquote>\n    <ul>\n      <li>a</li>\n    </ul>\n  </blockquote>\n  <p>para</p>\n</blockquote>"];
        yield 'follower past the quote column' => ["  > - a\n  +\n   para\n", "<blockquote>\n  <ul>\n    <li>a</li>\n  </ul>\n</blockquote>\n<p>para</p>"];
        yield 'quote at column 0' => ["> - a\n+\npara\n", "<blockquote>\n  <ul>\n    <li>a</li>\n  </ul>\n  <p>para</p>\n</blockquote>"];
    }

    #[DataProvider('documents')]
    public function testTheParagraphIsTheQuotes(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
