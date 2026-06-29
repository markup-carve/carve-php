<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Single-quote opener/closer behavior. A `'` in opener position (preceded by
 * whitespace / start, followed by a non-space, non-digit) is an OPENING quote
 * per the §8 flanking rule -- no matching closer is required -- matching
 * carve-js / carve-rs.
 */
class SmartQuoteScanTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testMatchedSingleQuotesBecomeCurly(): void
    {
        $this->assertSame(
            "<p>\u{2018}hello\u{2019}</p>",
            trim($this->converter->convert("'hello'")),
        );
    }

    public function testFlankingOpenerIsACurlyQuoteEvenWithNoCloser(): void
    {
        // A lone opener (space before, letter after) is an OPENING curly quote,
        // matching carve-js / carve-rs -- it does not require a matching closer.
        $this->assertSame(
            "<p>it \u{2018}is here</p>",
            trim($this->converter->convert("it 'is here")),
        );
        $this->assertSame(
            "<p>\u{2018}twas the night</p>",
            trim($this->converter->convert("'twas the night")),
        );
    }

    public function testQuoteAfterNonBreakingSpaceOpens(): void
    {
        // A non-breaking space is whitespace for quote flanking, so a quote
        // after one opens -- both the escaped `\ ` form and a literal U+00A0.
        $this->assertSame(
            "<p>say&nbsp;\u{2018}twas a fine&nbsp;\u{201C}day\u{201D}</p>",
            trim($this->converter->convert("say\\ 'twas a fine\\ \"day\"")),
        );
        $this->assertSame(
            "<p>a&nbsp;\u{2018}tis</p>",
            trim($this->converter->convert("a\u{00A0}'tis")),
        );
    }

    public function testApostropheCasesStayApostrophe(): void
    {
        // Mid-word and before-digit single quotes are apostrophes, not openers.
        $this->assertSame("<p>it\u{2019}s</p>", trim($this->converter->convert("it's")));
        $this->assertSame("<p>\u{2019}70s</p>", trim($this->converter->convert("'70s")));
    }

    public function testManySingleQuotesParseInLinearTime(): void
    {
        $source = str_repeat("'q' ", 16000);

        $start = hrtime(true);
        $this->converter->convert($source);
        $elapsed = (hrtime(true) - $start) / 1e9;

        // The opener decision is O(1) per quote; well under a second.
        $this->assertLessThan(2.0, $elapsed, "16000 single quotes took {$elapsed}s (quadratic regression?)");
    }
}
