<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * When `fmt` keeps the authored source around an erased line (carve#5012),
 * it still replaces the bytes the parser replaces.
 */
class TheAuthoredFallbackIsNormalizedLikeTheParseTest extends TestCase
{
    public function testInvalidUtf8AndNulBecomeReplacementCharacters(): void
    {
        $written = CarveConverter::toCarve("> a\n%% c\nb\n\ninvalid: \xFF and \0\n");

        $this->assertTrue(mb_check_encoding($written, 'UTF-8'));
        $this->assertStringNotContainsString("\0", $written);
        $this->assertStringContainsString("invalid: \u{FFFD} and \u{FFFD}", $written);
        $this->assertStringContainsString('%% c', $written);
    }
}
