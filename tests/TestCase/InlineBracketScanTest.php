<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\Group;
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

    /**
     * IN THE `scaling` GROUP because it is a WALL-CLOCK measurement. The
     * default suite runs under paratest, one process per core, so a timing test
     * there measures a machine every one of its siblings is loading - and it
     * turned `main` red on a commit touching no engine code (ratio 1.38 against
     * a 1.2 bound). The group has a runner of its own where nothing else is
     * running, which is the condition the measurement needs.
     */
    #[Group('scaling')]
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

    /**
     * IN THE `scaling` GROUP because it is a WALL-CLOCK measurement. The
     * default suite runs under paratest, one process per core, so a timing test
     * there measures a machine every one of its siblings is loading - and it
     * turned `main` red on a commit touching no engine code (ratio 1.38 against
     * a 1.2 bound). The group has a runner of its own where nothing else is
     * running, which is the condition the measurement needs.
     */
    #[Group('scaling')]
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
