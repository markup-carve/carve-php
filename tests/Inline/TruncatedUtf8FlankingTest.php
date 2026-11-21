<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Inline;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fuzz find: a lead byte cut short (`\xE6\x99` before `*`) was decoded with the bits of the bytes it had, a code
 * point that read as a letter, so the mark behind it could not open. C2-B-218 reads it as U+FFFD, which is no letter.
 */
final class TruncatedUtf8FlankingTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        $strong = "<p>a\u{FFFD}<strong>b</strong></p>";

        return [
            'three-byte lead with one continuation' => ["a\xE6\x99*b*", $strong],
            'four-byte lead with two continuations' => ["a\xF0\x9F\x99*b*", $strong],
            'four-byte lead with one continuation' => ["a\xF0\x9F*b*", $strong],
            'three-byte lead alone' => ["a\xE6*b*", $strong],
            'emphasis behind a cut sequence' => ["a\xE6\x99/b/", "<p>a\u{FFFD}<em>b</em></p>"],
            'valid letter still glues' => ["a\u{00E9}*b*", "<p>a\u{00E9}*b*</p>"],
        ];
    }

    #[DataProvider('cases')]
    public function testACutSequenceReadsAsReplacementCharacter(string $source, string $html): void
    {
        $this->assertSame($html, trim(Carve::toHtml($source . "\n")));
    }
}
