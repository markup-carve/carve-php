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
            preg_replace('/\n\z/', '', "<ul>\n  <li>\n    <blockquote><p>a</p></blockquote>\n  </li>\n  <li>+</li>\n</ul>\n"),
            CarveConverter::create()->convert("- > a\n- +\n"),
        );
    }

    public function testUnownedShallowerMarkerStillFoldsIntoQuote(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<ul>\n  <li>\n    <blockquote>\n      <blockquote><p>+\n- s</p></blockquote>\n    </blockquote>\n  </li>\n</ul>\n"),
            CarveConverter::create()->convert("- > > +\n  - s\n"),
        );
    }

    public function testFoldedFirstBlockMarkerKeepsQuotedItemParagraphOpen(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<blockquote>\n  <blockquote>\n    <ul>\n      <li>foo\n- +\nb</li>\n    </ul>\n  </blockquote>\n</blockquote>\n"),
            CarveConverter::create()->convert("> > - foo\n> - +\n> > b\n"),
        );
    }
}
