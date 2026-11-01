<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AttachedQuoteSiblingMarkerTest extends TestCase
{
    public function testOrderedMarkerAfterAttachedQuoteStartsBesideItsHostList(): void
    {
        $source = "- foo\n  - +\n  > q\n  1. x\n";
        $expected = "<ul>\n  <li>foo\n    <ul>\n      <li>\n        <blockquote><p>q</p></blockquote>\n      </li>\n    </ul>\n    <ol>\n      <li>x</li>\n    </ol>\n  </li>\n</ul>";

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testMatchingMarkerAfterAttachedQuoteJoinsHostsList(): void
    {
        $source = "- foo\n  - +\n  > q\n  - x\n";
        $expected = "<ul>\n  <li>foo\n    <ul>\n      <li>\n        <blockquote><p>q</p></blockquote>\n      </li>\n      <li>x</li>\n    </ul>\n  </li>\n</ul>";

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
