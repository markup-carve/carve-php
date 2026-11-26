<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class RestoredMarkerTest extends TestCase
{
    public function testC2B042FirstMarkerDeterminesStyle(): void
    {
        self::assertStringStartsWith('<ol type="a" start="22">', Carve::toHtml("v. a\nvi. b"));
        self::assertStringStartsWith('<ol type="i">', Carve::toHtml("i. a\nj. b"));
    }

    public function testC2B137CommentLineKeepsBlankLookaheadCount(): void
    {
        self::assertStringStartsWith('<ol type="a" start="22">', Carve::toHtml("v. a\n\n\n%% c\n\nvi. b"));
    }
}
