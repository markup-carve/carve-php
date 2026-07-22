<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the closing-paren short-circuit in parseLink() and
 * findLinkDestinationEnd(). Without it, an unclosed `(` after `]` made every
 * `[` re-run a char-by-char scan to end-of-text looking for `)`, so a `)`-less
 * run like `[a](` repeated was O(n^2). Correctness must be unchanged and the
 * run must stay linear.
 */
class LinkDestinationScanTest extends TestCase
{
    use ScalingGuardTrait;

    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testInlineLinkStillParses(): void
    {
        $this->assertSame('<p><a href="http://x">t</a></p>', trim($this->converter->convert('[t](http://x)')));
    }

    public function testImageStillParses(): void
    {
        // A sole image on a line is a block-level figure (no <p> wrapper).
        $this->assertSame('<img src="http://x" alt="alt">', trim($this->converter->convert('![alt](http://x)')));
    }

    public function testTitledLinkStillParses(): void
    {
        $this->assertSame(
            '<p><a href="http://x" title="T">t</a></p>',
            trim($this->converter->convert('[t](http://x "T")')),
        );
    }

    public function testUnclosedDestinationStaysLiteral(): void
    {
        $this->assertSame('<p>[a](</p>', trim($this->converter->convert('[a](')));
    }

    public function testUnclosedImageDestinationStaysLiteral(): void
    {
        $this->assertSame('<p>![a](</p>', trim($this->converter->convert('![a](')));
    }

    public function testUnclosedTitledDestinationStaysLiteral(): void
    {
        // The opening quote is smart-typographed; the fragment stays literal text.
        $this->assertSame('<p>[a](x “</p>', trim($this->converter->convert('[a](x "')));
    }

    /**
     * The scan short-circuit must make a `)`-less run scale linearly. The precise
     * quadratic detector is the doubling RATIO: linear work grows ~2x when the
     * input doubles, a quadratic term ~4x, so we require well under 3x -- this is
     * instrumentation-invariant (Xdebug/pcov coverage slows every run by the same
     * constant factor). The per-size wall is only a generous catastrophic backstop:
     * the old O(n^2) scan would take MINUTES at n=50000, so a ceiling of 20s
     * catches a full regression while leaving ample headroom for slow CI running
     * under coverage instrumentation.
     */
    #[DataProvider('unclosedDestinationProvider')]
    public function testUnclosedDestinationScalesLinearly(string $fragment): void
    {
        $this->assertScanScalesLinearly($this->converter, $fragment, '', "'{$fragment}'");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unclosedDestinationProvider(): array
    {
        return [
            'link' => ['[a]('],
            'image' => ['![a]('],
            'titled' => ['[a](x "'],
        ];
    }
}
