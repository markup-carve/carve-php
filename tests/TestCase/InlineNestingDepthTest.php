<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Inline-nesting DoS guard: deeply nested inline constructs are bounded so a
 * nested-link bomb cannot drive ~O(n^2) parsing.
 */
class InlineNestingDepthTest extends TestCase
{
    public function testDeeplyNestedLinksParseInBoundedTime(): void
    {
        $n = 20000;
        $src = str_repeat('[', $n) . 'x' . str_repeat('](#)', $n);

        $start = microtime(true);
        $html = (new CarveConverter())->convert($src);
        $elapsed = microtime(true) - $start;

        // Linear-ish after the depth cap; generously bounded to avoid flakiness.
        // The pre-fix quadratic blew well past this for n=20000.
        $this->assertLessThan(3.0, $elapsed);
        $this->assertNotSame('', $html);
    }

    public function testModeratelyNestedLinkStillRenders(): void
    {
        // A few real levels of nesting still parse normally (link text shows).
        $html = (new CarveConverter())->convert('[[inner](#a) outer](#b)');
        $this->assertStringContainsString('outer', $html);
    }
}
