<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Inline-nesting DoS guard: deeply nested inline constructs are bounded so a
 * nested-link bomb cannot drive ~O(n^2) parsing.
 */
class InlineNestingDepthTest extends TestCase
{
    public function testDeeplyNestedLinksParseInBoundedTime(): void
    {
        $n = 10000;
        $src = str_repeat('[', $n) . 'x' . str_repeat('](#)', $n);

        $start = microtime(true);
        $html = (new CarveConverter())->convert($src);
        $elapsed = microtime(true) - $start;

        // After the depth cap, parsing is ~linear (sub-second uninstrumented).
        // The bound is deliberately generous so it is not flaky under coverage
        // instrumentation (xdebug), while the pre-fix quadratic - tens of
        // seconds at n=10000 - would still blow far past it.
        $this->assertLessThan(15.0, $elapsed);
        $this->assertNotSame('', $html);
    }

    public function testModeratelyNestedLinkStillRenders(): void
    {
        // A few real levels of nesting still parse normally (link text shows).
        $html = (new CarveConverter())->convert('[[inner](#a) outer](#b)');
        $this->assertStringContainsString('outer', $html);
    }
}
