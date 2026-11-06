<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class FirstBlockDescriptionEndsAtNextBlockTest extends TestCase
{
    public function testColumnZeroDivStartsAfterDescription(): void
    {
        $source = ":: t\n: +\nh\n:::\n";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>h</dd>\n</dl>\n<div>\n\n</div>";

        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testIndentedOpenerIsTextOfAnAttachedParagraph(): void
    {
        $source = ":: t\n: +\nh\n  # heading\n";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>h\n# heading</dd>\n</dl>";

        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testAnAttachedListKeepsItsNestedMarker(): void
    {
        $source = ":: t\n: +\n- a\n  - b\n";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <ul>\n      <li>a\n        <ul>\n          <li>b</li>\n        </ul>\n      </li>\n    </ul>\n  </dd>\n</dl>";

        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testAHeadingEndsAtTheNextLineLeftOfTheBody(): void
    {
        $source = ":: t\n: +\n# x\nx\n";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <h1 id=\"x\">x</h1>\n  </dd>\n</dl>\n<p>x</p>";

        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testAQuoteEndsAtAColumnZeroDiv(): void
    {
        $source = ":: t\n: +\n> q\n:::\n";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <blockquote><p>q</p></blockquote>\n  </dd>\n</dl>\n<div>\n\n</div>";

        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testTextAtTheBodyColumnAfterAFenceStaysInTheBody(): void
    {
        $source = ":: t\n: +\n```\nc\n```\n  x\n";
        $expected = "<dl>\n  <dt>t</dt>\n  <dd>\n    <pre><code>c\n</code></pre>\n    <p>x</p>\n  </dd>\n</dl>";

        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testACommentSpanAfterAnAttachedQuoteStaysWhole(): void
    {
        $source = "- p\n\n  :: t\n  : +\n  > q\n  %%%\n  :::\n  %%%\n";
        $expected = "<ul>\n  <li><p>p</p>\n    <dl>\n      <dt>t</dt>\n      <dd>\n        <blockquote><p>q</p></blockquote>\n      </dd>\n    </dl>\n  </li>\n</ul>";

        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
