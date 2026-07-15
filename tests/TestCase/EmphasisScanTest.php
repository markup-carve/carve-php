<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the no-closer memo in parseDelimited(). Each `_`/`*`/`~`/`=`/`/`
 * opener scans forward for a matching closer. When every candidate closer is
 * alnum-blocked (a `)`-less run like `_a](` repeated), that scan runs to
 * end-of-text and fails -- so without the memo N openers each do O(n) work and
 * the whole run is O(n^2). Correctness must be unchanged and the run must stay
 * linear.
 */
class EmphasisScanTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testUnclosableOpenerStaysLiteral(): void
    {
        $this->assertSame('<p>_a](_a](</p>', trim($this->converter->convert('_a](_a](')));
    }

    public function testUnclosableStrongOpenerStaysLiteral(): void
    {
        $this->assertSame('<p>*a](*a](</p>', trim($this->converter->convert('*a](*a](')));
    }

    public function testValidEmphasisAfterAlnumBlockedCloserStillParses(): void
    {
        // The first `_` cannot close before an alnum, so it pairs with the last
        // `_`; the memo must not suppress this genuine match.
        $this->assertSame('<p><u>a](_b</u></p>', trim($this->converter->convert('_a](_b_')));
    }

    /**
     * Trailing-paren shape: each `_`/`*` opener reaches a `](` and its
     * emphasis-close scan skips the link destination to the SINGLE far `)`.
     * Bounding that destination probe (a per-text next-unescaped-`)` table)
     * keeps the run linear; output must be unchanged.
     */
    public function testTrailingParenOpenerStaysLiteral(): void
    {
        $this->assertSame('<p>_a](_a](_a]()</p>', trim($this->converter->convert('_a](_a](_a]()')));
    }

    public function testTrailingParenEscapedCloserSkipsInEmphasisScan(): void
    {
        // findLinkDestinationEnd skips the escaped `)` and lands on the real one,
        // so the emphasis spans the whole link; the link's own destination stops
        // at the first `)` (escapes do not protect it there).
        $this->assertSame(
            '<p><u><a href="u\\">a</a>v)</u></p>',
            trim($this->converter->convert('_[a](u\\)v)_')),
        );
    }

    /**
     * The no-closer memo must make a `)`-less, alnum-blocked run scale linearly.
     * The precise quadratic detector is the doubling RATIO: linear work grows
     * ~2x when the input doubles, a quadratic term ~4x, so we require well under
     * 3x -- instrumentation-invariant (Xdebug/pcov coverage slows every run by
     * the same constant factor). The per-size wall is only a generous
     * catastrophic backstop: the old O(n^2) scan would take MINUTES at n=50000,
     * so a ceiling of 20s catches a full regression while leaving ample headroom
     * for slow CI running under coverage instrumentation.
     */
    #[DataProvider('unclosableOpenerProvider')]
    public function testUnclosableOpenerScalesLinearly(string $fragment): void
    {
        $small = str_repeat($fragment, 25000);
        $large = str_repeat($fragment, 50000);

        // Best-of-N wall-clock. A single timed run is noisy under a full suite
        // (GC pauses, memory pressure after thousands of prior tests can spike a
        // linear run's ratio above 3x); the minimum of a few runs filters those
        // upward transients so the ratio reflects real algorithmic scaling. A
        // warmup convert() primes any per-instance caches before timing.
        $elapsedSmall = $this->bestConvertTime($small);
        $elapsedLarge = $this->bestConvertTime($large);

        $this->assertLessThan(
            20.0,
            $elapsedSmall,
            "25000x '{$fragment}' took {$elapsedSmall}s (quadratic regression?)",
        );
        $this->assertLessThan(
            20.0,
            $elapsedLarge,
            "50000x '{$fragment}' took {$elapsedLarge}s (quadratic regression?)",
        );

        // Guard against a small-but-nonzero quadratic term: doubling n must not
        // triple the time. A floor avoids flakiness when both runs are sub-ms.
        $ratio = $elapsedLarge / max($elapsedSmall, 0.001);
        $this->assertLessThan(
            3.0,
            $ratio,
            "Doubling input scaled time {$ratio}x (linear ~2x, quadratic ~4x): "
                . "small={$elapsedSmall}s large={$elapsedLarge}s",
        );
    }

    /**
     * Fastest of three convert() runs for the given input, in seconds, after a
     * warmup run. The minimum is robust against upward timing noise (GC, CPU
     * contention) that would otherwise make a linear run look super-linear.
     */
    private function bestConvertTime(string $input): float
    {
        $this->converter->convert($input);

        $best = INF;
        for ($i = 0; $i < 3; $i++) {
            $start = hrtime(true);
            $this->converter->convert($input);
            $best = min($best, (hrtime(true) - $start) / 1e9);
        }

        return $best;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unclosableOpenerProvider(): array
    {
        return [
            'underline' => ['_a]('],
            'strong' => ['*a]('],
            'strike' => ['~a]('],
            'highlight' => ['=a]('],
        ];
    }

    /**
     * Trailing-paren shape (`_a](` repeated + a single far `)`): the destination
     * probe must be bounded so N openers do not each re-scan the same tail.
     */
    #[DataProvider('trailingParenProvider')]
    public function testTrailingParenScalesLinearly(string $fragment): void
    {
        $small = str_repeat($fragment, 25000) . ')';
        $large = str_repeat($fragment, 50000) . ')';

        $elapsedSmall = $this->bestConvertTime($small);
        $elapsedLarge = $this->bestConvertTime($large);

        $this->assertLessThan(20.0, $elapsedSmall, "25000x '{$fragment}' + ')' took {$elapsedSmall}s");
        $this->assertLessThan(20.0, $elapsedLarge, "50000x '{$fragment}' + ')' took {$elapsedLarge}s");

        $ratio = $elapsedLarge / max($elapsedSmall, 0.001);
        $this->assertLessThan(
            3.0,
            $ratio,
            "Doubling input scaled time {$ratio}x: small={$elapsedSmall}s large={$elapsedLarge}s",
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function trailingParenProvider(): array
    {
        return [
            'underline-trailparen' => ['_a]('],
            'strong-trailparen' => ['*a]('],
            'boldital-trailparen' => ['/*a]('],
        ];
    }
}
