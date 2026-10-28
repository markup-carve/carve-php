<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LazyDescriptionConsecutivePlusTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'second plus is paragraph' => [":: m\n: f\nl\n+\n+\n", "<p>f\nl</p>\n    <p>+</p>"];
        yield 'plus attaches text' => [":: m\n: f\nl\n+\nq\n", "<p>f\nl</p>\n    <p>q</p>"];
        yield 'terminal plus stays lazy' => [":: m\n: f\nl\n+\n", "<dd>f\nl\n+</dd>"];
    }

    #[DataProvider('cases')]
    public function testLazyDescriptionKeepsItsContinuationOwner(string $source, string $fragment): void
    {
        self::assertStringContainsString($fragment, (new CarveConverter())->convert($source));
    }
}
