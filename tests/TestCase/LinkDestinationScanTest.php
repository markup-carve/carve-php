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
        $small = str_repeat($fragment, 25000);
        $large = str_repeat($fragment, 50000);

        $startSmall = hrtime(true);
        $this->converter->convert($small);
        $elapsedSmall = (hrtime(true) - $startSmall) / 1e9;

        $startLarge = hrtime(true);
        $this->converter->convert($large);
        $elapsedLarge = (hrtime(true) - $startLarge) / 1e9;

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
