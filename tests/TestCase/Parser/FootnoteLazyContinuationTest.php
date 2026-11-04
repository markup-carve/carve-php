<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class FootnoteLazyContinuationTest extends TestCase
{
    public function testRefusedContinuationMarkerRemainsInOpenNoteParagraph(): void
    {
        foreach (
            [
                "[^a]: intro\n+\n more\n\nsee[^a]\n",
                "[^a]: intro\n+\n  more\n\nsee[^a]\n",
                "[^a]: +\n flush\n\nsee[^a]\n",
            ] as $source
        ) {
            $html = (new CarveConverter())->convert($source);
            self::assertStringNotContainsString('<p>+</p>', $html);
            self::assertStringContainsString('role="doc-endnotes"', $html);
            // A refused marker renders nothing (carve#5013).
            self::assertStringNotContainsString('+', $html);
        }
    }

    public function testParagraphClosureReleasesFollowingDocumentLine(): void
    {
        $source = "x[^a]\n\n[^a]: note\n+\n\npara\n";
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString('<p>para</p>', $html);
        self::assertStringContainsString('<p>note<a href', $html);
    }
}
