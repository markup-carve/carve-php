<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class UnpairedDelimiterFormTest extends TestCase
{
    public function testARepeatedDelimiterBeforeAClosingBraceIsText(): void
    {
        foreach (["*a**}*\n", "/a//}/\n", "x */**}* y\n"] as $source) {
            self::assertSame($source, Carve::format($source));
        }
    }

    public function testAttachedAttributesSeparateTheCloserFromFollowingText(): void
    {
        foreach (["*a*{.c}a\n", "*a b*{.c}a\n"] as $source) {
            self::assertSame($source, Carve::format($source));
        }
    }

    public function testADelimiterBesideASoftBreakCannotPair(): void
    {
        foreach (["*a\n* b*\n", "*a *\nb*\n", "> /a\n> / b/\n", "*{_\n* ]*\n"] as $source) {
            self::assertSame($source, Carve::format($source));
        }
    }
}
