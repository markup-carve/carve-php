<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Inline;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\Linearity;
use PHPUnit\Framework\TestCase;

/**
 * Fuzz find: with one malformed byte in the source, C2-B-218 repaired the expansion of every abbreviation use on its
 * own, so the parse took uses times expansion length (32 KB took 7.8 s).
 */
final class MalformedUtf8SharedStringTest extends TestCase
{
    public function testParseIsLinear(): void
    {
        $small = self::source(2048);
        $large = self::source(16384);
        $excess = Linearity::excess(static fn () => Carve::parse($small), static fn () => Carve::parse($large), 8, 1);

        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }

    public function testEveryUseKeepsTheRepairedExpansion(): void
    {
        $html = Carve::toHtml("*[A]: x\xAE" . str_repeat('y', 40) . "\n\nA \xAE A\n");
        $title = "x\u{FFFD}" . str_repeat('y', 40);

        $this->assertSame(2, substr_count($html, '<abbr title="' . $title . '">A</abbr>'));
        $this->assertStringContainsString("\u{FFFD}", $html);
    }

    private static function source(int $size): string
    {
        $uses = trim(str_repeat('A ', intdiv($size, 8)));

        return '*[A]: ' . str_repeat('x', intdiv($size, 2)) . "\n\n" . $uses . " \xAE " . $uses . "\n";
    }
}
