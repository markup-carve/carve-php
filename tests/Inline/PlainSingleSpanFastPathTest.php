<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Inline;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class PlainSingleSpanFastPathTest extends TestCase
{
    public function testPlainContentKeepsItsSourceExtentAndEscapesAtRenderTime(): void
    {
        // C2-I-002: trailing spaces are not inline content; C2-H-002: HTML text is escaped.
        $source = "a&b  \n";
        $tree = json_decode(Carve::toJson($source), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('a&b', $tree['children'][0]['children'][0]['value']);
        self::assertSame(0, $tree['children'][0]['children'][0]['pos']['startOffset']);
        self::assertSame(3, $tree['children'][0]['children'][0]['pos']['endOffset']);
        self::assertSame('<p>a&amp;b</p>', Carve::toHtml($source));
    }

    public function testTypographyTriggerStillUsesTheScanner(): void
    {
        // C2-I-104: punctuation replacements remain active in otherwise plain content.
        self::assertSame('<p>mark…</p>', Carve::toHtml("mark...  \n"));
    }
}
