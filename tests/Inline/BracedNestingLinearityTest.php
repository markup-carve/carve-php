<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Inline;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\Linearity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fuzz find: each braced closer copied the stack of open braces, so `{=` nested n deep parsed in n squared time
 * (16,000 levels took 1.5 s).
 */
final class BracedNestingLinearityTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function marks(): array
    {
        return ['highlight' => ['='], 'strong' => ['*'], 'superscript' => ['^'], 'insert' => ['+']];
    }

    #[DataProvider('marks')]
    public function testParseIsLinear(string $mark): void
    {
        $small = self::source($mark, 2000);
        $large = self::source($mark, 16000);
        $excess = Linearity::excess(static fn () => Carve::parse($small), static fn () => Carve::parse($large), 8, 1);

        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }

    private static function source(string $mark, int $depth): string
    {
        return str_repeat('{' . $mark, $depth) . 'a' . str_repeat($mark . '}', $depth) . "\n";
    }
}
