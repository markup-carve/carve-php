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
    use ScalingGuardTrait;

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
        $this->assertScanScalesLinearly($this->converter, $fragment, '', "'{$fragment}'");
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
        $this->assertScanScalesLinearly($this->converter, $fragment, ')', "'{$fragment}' + ')'");
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
