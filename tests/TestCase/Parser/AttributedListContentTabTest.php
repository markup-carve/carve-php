<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttributedListContentTabTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'empty bullet attributes' => ["-{} \tx\n", "<li>\tx</li>"];
        yield 'classed bullet attributes' => ["-{.c} \tx\n", "<li class=\"c\">\tx</li>"];
        yield 'ordered marker attributes' => ["1.{} \tx\n", "<li>\tx</li>"];
        yield 'nested marker attributes' => ["- a\n  -{} \tx\n", "<li>\tx</li>"];
        yield 'missing separator stays literal' => ["-{}\tx\n", '<p>-{}' . "\t" . 'x</p>'];
    }

    #[DataProvider('cases')]
    public function testContentTabAfterMarkerAttributes(string $source, string $expected): void
    {
        self::assertStringContainsString($expected, (new CarveConverter())->convert($source));
    }
}
