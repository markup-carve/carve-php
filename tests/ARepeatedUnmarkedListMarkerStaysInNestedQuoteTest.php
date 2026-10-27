<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ARepeatedUnmarkedListMarkerStaysInNestedQuoteTest extends TestCase
{
    public function testEachMarkerFoldsIntoOpenNestedQuoteParagraph(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<ul>\n  <li>\n    <blockquote>\n      <blockquote><p>+\n- s\n- s\n- s</p></blockquote>\n    </blockquote>\n  </li>\n</ul>\n"),
            CarveConverter::create()->convert("- > > +\n  - s\n  - s\n  - s\n"),
        );
    }

    public function testBlankLineEndsTheParagraph(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<ul>\n  <li>\n    <blockquote>\n      <blockquote><p>+\n- s</p></blockquote>\n    </blockquote>\n    <ul>\n      <li>s</li>\n    </ul>\n  </li>\n</ul>\n"),
            CarveConverter::create()->convert("- > > +\n  - s\n\n  - s\n"),
        );
    }
}
