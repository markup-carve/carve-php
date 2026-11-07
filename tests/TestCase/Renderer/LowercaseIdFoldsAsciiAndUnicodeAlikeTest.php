<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Renderer\HeadingIdTracker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Opt-in lowercasing folds per code point. An all-ASCII id takes a byte fold,
 * which must agree with the per-code-point fold for every ASCII letter.
 */
class LowercaseIdFoldsAsciiAndUnicodeAlikeTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function idProvider(): array
    {
        return [
            'ascii' => ['Hello World', 'hello-world'],
            'ascii with digits' => ['Step 2B', 'step-2b'],
            'latin-1' => ['ÄÖÜ Straße', 'äöü-straße'],
            'greek capital sigma has no final form' => ['ΣΟΦΟΣ', 'σοφοσ'],
            'dotted capital I' => ['İx', mb_strtolower('İ', 'UTF-8') . 'x'],
            'kelvin sign' => ["\u{212A}elvin", 'kelvin'],
            'mixed' => ['ABC ÉCOLE', 'abc-école'],
        ];
    }

    #[DataProvider('idProvider')]
    public function testLowercaseFoldsPerCodePoint(string $text, string $expected): void
    {
        $tracker = new HeadingIdTracker();
        $tracker->setLowercase(true);

        $this->assertSame($expected, $tracker->normalizeId($text));
    }

    public function testFoldIsOptIn(): void
    {
        $this->assertSame('Hello-World', (new HeadingIdTracker())->normalizeId('Hello World'));
    }
}
