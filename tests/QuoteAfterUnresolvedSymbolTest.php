<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuoteAfterUnresolvedSymbolTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'double quote' => [':a:"', '<p>:a:“</p>'];
        yield 'single quote' => [":name:'", '<p>:name:‘</p>'];
        yield 'paired quotes' => ['x :a:"q"', '<p>x :a:“q”</p>'];
    }

    #[DataProvider('cases')]
    public function testTheSymbolEndColonOpensTheFollowingQuote(string $source, string $expected): void
    {
        self::assertSame(preg_replace('/\n\z/', '', $expected . "\n"), (new CarveConverter())->convert($source));
    }
}
