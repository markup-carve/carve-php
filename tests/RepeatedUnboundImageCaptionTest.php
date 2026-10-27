<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class RepeatedUnboundImageCaptionTest extends TestCase
{
    public function testEachCaretLineSurvivesAnUnresolvedImage(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<p>![a][r]\n^ cap</p>\n<p>^ cap</p>\n"),
            CarveConverter::create()->convert("![a][r]\n^ cap\n^ cap\n"),
        );
    }
}
