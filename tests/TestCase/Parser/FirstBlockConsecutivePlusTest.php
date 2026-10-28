<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FirstBlockConsecutivePlusTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'second plus is content at end' => ["- +\n+\n", "<ul>\n  <li>+</li>\n</ul>"];
        yield 'second plus attaches follower' => ["- +\n+\ntext\n", "<ul>\n  <li>text</li>\n</ul>"];
        yield 'third plus stays content' => ["- +\n+\n+\n", "<ul>\n  <li>+</li>\n</ul>"];
        yield 'sibling after content' => ["- +\n+\n- b\n", "<ul>\n  <li>+</li>\n  <li>b</li>\n</ul>"];
    }

    #[DataProvider('cases')]
    public function testFirstBlockPlusOwnership(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
