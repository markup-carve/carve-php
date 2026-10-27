<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnclosedEmptyMathSpanTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'inline at end of file' => ['$`', '<p><span class="math inline" role="math">\(\)</span></p>'];
        yield 'inline before line feed' => ["$`\n", '<p><span class="math inline" role="math">\(\)</span></p>'];
        yield 'inline before carriage return' => ["$`\r", '<p><span class="math inline" role="math">\(\)</span></p>'];
        yield 'display at end of file' => ['$$`', '<p><span class="math display" role="math">\[\]</span></p>'];
    }

    #[DataProvider('cases')]
    public function testUnclosedEmptyMathIsAValidSpan(string $source, string $expected): void
    {
        self::assertSame(preg_replace('/\n\z/', '', $expected . "\n"), (new CarveConverter())->convert($source));
    }
}
