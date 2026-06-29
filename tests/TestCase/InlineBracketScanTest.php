<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Guards the strpos short-circuit in parseLink(). Without it, every `[` ran a
 * char-by-char scan to end-of-text looking for a closing `]`, so an unbalanced
 * run was O(n^2). Correctness must be unchanged and the run must stay linear.
 */
class InlineBracketScanTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testInlineLinkStillParses(): void
    {
        $this->assertSame('<p><a href="http://x">t</a></p>', trim($this->converter->convert('[t](http://x)')));
    }

    public function testReferenceLinkStillParses(): void
    {
        $this->assertSame('<p><a href="http://x">t</a></p>', trim($this->converter->convert("[t][r]\n\n[r]: http://x")));
    }

    public function testUnbalancedBracketsStayLiteral(): void
    {
        $this->assertSame('<p>[[[x</p>', trim($this->converter->convert('[[[x')));
    }

    public function testLongUnbalancedRunParsesInLinearTime(): void
    {
        $source = str_repeat('[', 4000) . 'x';

        $start = hrtime(true);
        $this->converter->convert($source);
        $elapsed = (hrtime(true) - $start) / 1e9;

        // Linear is ~30ms; the previous O(n^2) scan was far worse at this size.
        $this->assertLessThan(1.0, $elapsed, "4000 open brackets took {$elapsed}s (quadratic regression?)");
    }

    public function testBalancedNestedBracketsStayLiteral(): void
    {
        // Balanced `]` exist, but no `](`/`][`/`]{` trigger -> not a link.
        $this->assertSame('<p>[[[x]]]</p>', trim($this->converter->convert('[[[x]]]')));
    }

    public function testDeepBalancedBracketsParseInLinearTime(): void
    {
        // Balanced brackets pass the `]` guard, so only the link-trigger memo
        // keeps this linear instead of O(n^2) over the bracket-depth scan.
        $source = str_repeat('[', 20000) . 'x' . str_repeat(']', 20000);

        $start = hrtime(true);
        $this->converter->convert($source);
        $elapsed = (hrtime(true) - $start) / 1e9;

        $this->assertLessThan(2.0, $elapsed, "20000 nested brackets took {$elapsed}s (quadratic regression?)");
    }
}
