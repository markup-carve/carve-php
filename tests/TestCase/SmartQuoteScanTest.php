<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Guards the single-quote opener/closer matching in buildSingleQuoteMatchCache().
 * It must pair quotes with a single stack merge (O(n)); the previous nested
 * closer x opener scan was O(n^2) (16k quotes ~7s). Correctness must be
 * unchanged.
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

    public function testApostropheStaysWhenNoCloser(): void
    {
        // A lone opener with no matching closer is an apostrophe, not a quote.
        $this->assertStringNotContainsString("\u{2018}", $this->converter->convert("it 'is here"));
    }

    public function testManySingleQuotesParseInLinearTime(): void
    {
        $source = str_repeat("'q' ", 16000);

        $start = hrtime(true);
        $this->converter->convert($source);
        $elapsed = (hrtime(true) - $start) / 1e9;

        // Linear is well under a second; the previous O(n^2) match was ~7s.
        $this->assertLessThan(2.0, $elapsed, "16000 single quotes took {$elapsed}s (quadratic regression?)");
    }
}
