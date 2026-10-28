<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NestedMarkerTermLazyFoldTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'definition-shaped lazy line' => ["- - :: t\n  : a\n", "<dt>t\n: a</dt>"];
        yield 'plain lazy line' => ["- - :: t\n  a\n", "<dt>t\na</dt>"];
        yield 'heading remains an outer block' => ["- - :: t\n  # h\n", '<h1 id="h">h</h1>'];
        yield 'table remains an outer block' => ["- - :: t\n  |a|\n", '<td>a</td>'];
    }

    #[DataProvider('cases')]
    public function testMarkerColumnTermFollower(string $source, string $fragment): void
    {
        self::assertStringContainsString($fragment, (new CarveConverter())->convert($source));
    }
}
