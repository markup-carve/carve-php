<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `backtick_run`: the closer is a run of the SAME count and also MAXIMAL, so a
 * longer run closes nothing and the opener stays unclosed and opaque. The
 * emphasis lookahead read only the character after a candidate closer and so
 * disagreed with the main parser (markup-carve/carve-php#2029).
 */
class ACodeSpanCloserIsAMaximalRunForTheLookaheadTooTest extends TestCase
{
    protected function html(string $source): string
    {
        return CarveConverter::create()->convert($source);
    }

    /**
     * @return array<string, array<string>>
     */
    public static function opaqueProvider(): array
    {
        return [
            'a highlight delimiter' => ["=`b``=\n", "<p>=<code>b``=</code></p>\n"],
            'an emphasis delimiter' => ["/`b``/\n", "<p>/<code>b``/</code></p>\n"],
            'a strike delimiter' => ["~`b``~\n", "<p>~<code>b``~</code></p>\n"],
        ];
    }

    /**
     * The delimiter in front is what made the lookahead run, so each one is
     * driven on its own rather than folded into a single assertion.
     */
    #[DataProvider('opaqueProvider')]
    public function testTheDelimiterAfterAnUnclosedRunIsVerbatim(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * The same string without a delimiter never diverged: only the main loop
     * runs there, and it already checked both sides.
     */
    public function testTheMainLoopWasAlreadyRight(): void
    {
        $this->assertSame("<p><code>b``=</code></p>\n", $this->html("`b``=\n"));
    }

    /**
     * BOUND: an equal-length closer still closes, so the lookahead has not been
     * turned off.
     */
    public function testAnEqualLengthCloserStillCloses(): void
    {
        $this->assertSame("<p><mark><code>b</code></mark></p>\n", $this->html("=`b`=\n"));
    }

    /**
     * BOUND: a longer run INSIDE a span is content, not a closer.
     */
    public function testALongerRunInsideASpanIsContent(): void
    {
        $this->assertSame("<p><code>a``b</code></p>\n", $this->html("`a``b`\n"));
    }
}
