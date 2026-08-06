<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * One recognizer for the `+` continuation marker (carve-php#929).
 *
 * §17 L3 says "a line whose only content is `+`". Trailing whitespace is not
 * content, and the executable spec's own recognizer is `/^\+[ \t]*$/`.
 *
 * This engine spelled the test four ways across seven sites - `trim()`,
 * `rtrim()`, and twice against an already-`ltrim`ed value - so whether a
 * trailing space broke the marker depended on which code path a given document
 * happened to reach. That asymmetry is also what produced carve-php#925.
 */
class OneRecognizerForTheContinuationMarkerTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testATrailingSpaceDoesNotBreakTheMarkerInATightItem(): void
    {
        $html = $this->html("- a\n+ \nb\n\nx\n");

        $this->assertStringNotContainsString('+', $html, $html);
    }

    public function testATrailingSpaceDoesNotBreakTheMarkerAfterABlankLine(): void
    {
        // The path carve-php#925 fixed, which reached a different recognizer.
        $html = $this->html("- a\n\n  b\n+ \nc\n\nx\n");

        $this->assertStringNotContainsString('+', $html, $html);
        $this->assertStringContainsString('<p>c</p>', $html, $html);
    }

    public function testATrailingTabDoesNotBreakTheMarker(): void
    {
        $html = $this->html("- a\n+\t\nb\n\nx\n");

        $this->assertStringNotContainsString('+', $html, $html);
    }

    public function testATrailingSpaceDoesNotBreakTheFirstBlockForm(): void
    {
        // `- +` opens an item whose body is the following flush-left block.
        $html = $this->html("- + \nb\n\nx\n");

        $this->assertStringNotContainsString('+', $html, $html);
    }

    public function testATrailingSpaceDoesNotBreakTheQuoteForm(): void
    {
        $html = $this->html("> q\n+ \nb\n\nx\n");

        $this->assertStringNotContainsString('+', $html, $html);
    }

    public function testAnIndentedMarkerIsStillNotTheQuoteForm(): void
    {
        // The boundary the quote form's recognizer carries: it requires column
        // 0, so an indented `+` after a quoted line is not the marker. Relaxing
        // the whitespace test must not relax the COLUMN test with it.
        $html = $this->html("> q\n  +\nb\n\nx\n");

        $this->assertStringContainsString('+', $html, $html);
    }

    public function testAPlusWithContentIsStillNotTheMarker(): void
    {
        // §11 N1: `+` is not a Carve bullet, which is what makes a LONE `+`
        // unambiguous. `+ b` is neither marker nor bullet.
        $html = $this->html("- a\n+ b\n\nx\n");

        $this->assertStringContainsString('+ b', $html, $html);
    }
}
