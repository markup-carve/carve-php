<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Inline;

use ErrorException;
use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

/**
 * Fuzz find: a malformed sequence that decoded to a surrogate, a value past U+10FFFF or an overlong form gave a code
 * point that is no character, and a smart quote behind it threw a TypeError. C2-B-218 reads it as U+FFFD.
 */
final class MalformedUtf8NeighborTest extends TestCase
{
    /**
     * @var array<string>
     */
    private const MALFORMED = [
        "\xBC\x91\xAD\xAD", "\xF4\x90\x80\x80", "\xED\xA0\x80", "\xED\xBF\xBF", "\xE0\x80\x80", "\xC0\xAF", "\xC1\xBF",
        "\xF0\x80\x80\x80", "\xF8\x88\x80\x80", "\xFF", "\xC3", "\x80", "\xE2\x82",
    ];

    /**
     * @var array<string>
     */
    private const FOLLOWERS = ['"x"', "'x'", "'", '"', '*a*', '_a_', '{=a=}', '[a](b)', ':s:', '@a', '...', '--', '$`a`'];

    public function testEveryMalformedNeighborReadsAsReplacementCharacter(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $count = 0;
            foreach (self::MALFORMED as $bad) {
                foreach (self::FOLLOWERS as $follower) {
                    foreach (['', 'a ', '*', '{'] as $prefix) {
                        foreach ([$prefix . $bad . $follower, $follower . $bad, $prefix . $follower . $bad . $follower] as $source) {
                            $html = Carve::toHtml($source . "\n");
                            $this->assertTrue(mb_check_encoding($html, 'UTF-8'), bin2hex($source));
                            $this->assertStringContainsString("\u{FFFD}", $html, bin2hex($source));
                            Carve::format($source . "\n");
                            $count++;
                        }
                    }
                }
            }
            $this->assertSame(count(self::MALFORMED) * count(self::FOLLOWERS) * 12, $count);
        } finally {
            restore_error_handler();
        }
    }

    public function testSmartQuoteBehindASurrogateClosesAsBehindAnyCharacter(): void
    {
        $this->assertSame("<p>\u{FFFD}\u{FFFD}\u{FFFD}\u{201D}</p>", Carve::toHtml("\xED\xA0\x80\"\n"));
        $this->assertSame("<p>\u{FFFD}\u{201D}</p>", Carve::toHtml("\xC3\"\n"));
    }
}
