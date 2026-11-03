<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `+` at an indented quote's marker column attaches the next block whether or not positions are tracked,
 * so the writer, which tracks them, keeps the split (carve-php#4254). Expected HTML is the spec model's.
 */
final class AQuotePlusReadsTheSameWithPositionsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function documents(): iterable
    {
        yield 'pack case roundtrip-615415506b26 (#4254)' => ["  > q\n- a\n  +\n  para\n", "<blockquote>\n  <p>q\n- a</p>\n  <p>para</p>\n</blockquote>", "> q\n> - a\n>\n> para\n"];
        yield 'indented quote' => ["  > q\n  +\n  para\n", "<blockquote>\n  <p>q</p>\n  <p>para</p>\n</blockquote>", "> q\n>\n> para\n"];
        yield 'one-space quote after a lazy line' => [" > q\nb\n +\n para\n", "<blockquote>\n  <p>q\nb</p>\n  <p>para</p>\n</blockquote>", "> q\n> b\n>\n> para\n"];
        yield 'three-space quote attaching a list' => ["   > q\n   +\n   - x\n", "<blockquote>\n  <p>q</p>\n  <ul>\n    <li>x</li>\n  </ul>\n</blockquote>", "> q\n>\n> - x\n"];
    }

    #[DataProvider('documents')]
    public function testPositionsDoNotChangeTheReading(string $source, string $html, string $carve): void
    {
        $tracked = CarveConverter::create(new BlockParser(trackPositions: true));
        self::assertSame($html, trim($tracked->convert($source)));
        self::assertSame($html, trim((new CarveConverter())->convert($source)));
        self::assertSame($carve, CarveConverter::toCarve($source));
        self::assertSame($html, trim((new CarveConverter())->convert($carve)));
    }
}
