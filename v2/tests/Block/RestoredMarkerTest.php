<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Block;

use MarkupCarve\Carve2\Carve2;
use PHPUnit\Framework\TestCase;

final class RestoredMarkerTest extends TestCase
{
    public function testC2B042SingleRomanLetterUsesNextMarker(): void
    {
        self::assertStringStartsWith('<ol type="i" start="5">', Carve2::toHtml("v. a\nvi. b"));
        self::assertStringStartsWith('<ol type="a" start="9">', Carve2::toHtml("i. a\nj. b"));
    }

    public function testC2B137CommentLineKeepsBlankLookaheadCount(): void
    {
        self::assertStringStartsWith('<ol type="a" start="22">', Carve2::toHtml("v. a\n\n\n%% c\n\nvi. b"));
    }
}
