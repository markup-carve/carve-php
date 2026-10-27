<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class AFirstBlockMarkerInASiblingListItemTest extends TestCase
{
    public function testSiblingItemOwnsAnEmptyFirstBlockMarker(): void
    {
        self::assertSame(
            "<ul>\n  <li>\n    <blockquote><p>a</p></blockquote>\n  </li>\n  <li></li>\n</ul>\n",
            CarveConverter::create()->convert("- > a\n- +\n"),
        );
    }

    public function testUnownedShallowerMarkerStillFoldsIntoQuote(): void
    {
        self::assertSame(
            "<ul>\n  <li>\n    <blockquote>\n      <blockquote><p>+\n- s</p></blockquote>\n    </blockquote>\n  </li>\n</ul>\n",
            CarveConverter::create()->convert("- > > +\n  - s\n"),
        );
    }
}
