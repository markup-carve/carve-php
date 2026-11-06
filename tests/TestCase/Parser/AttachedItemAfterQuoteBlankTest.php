<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class AttachedItemAfterQuoteBlankTest extends TestCase
{
    public function testAMarkerBelowTheNestedContentColumnStartsASiblingList(): void
    {
        $source = "- a\n  - +\n  + w1\n   9. w2\n";
        $expected = "<ul>\n  <li>a\n    <ul>\n      <li>+ w1</li>\n    </ul>\n    <ol start=\"9\">\n      <li>w2</li>\n    </ol>\n  </li>\n</ul>";
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }

    public function testAMarkerAtTheNestedContentColumnStartsAChildList(): void
    {
        $source = "- a\n  - +\n  + w1\n    9. w2\n";
        $expected = "<ul>\n  <li>a\n    <ul>\n      <li>+ w1\n        <ol start=\"9\">\n          <li>w2</li>\n        </ol>\n      </li>\n    </ul>\n  </li>\n</ul>";
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }

    public function testASecondQuotedContinuationReachesItsItem(): void
    {
        $source = "> - a\n> +\n> # h\n>\n> +\n> # h\n";
        $expected = "<blockquote>\n  <ul>\n    <li>a\n      <h1 id=\"h\">h</h1>\n      <h1 id=\"h-2\">h</h1>\n    </li>\n  </ul>\n</blockquote>";
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }

    public function testAdjacentOrderedItemsStayInOneListAfterAnAttachedQuote(): void
    {
        $source = "- +\n> > q\n  1. a\n  2. b\n";
        $actual = CarveConverter::create()->convert($source);

        self::assertSame(1, substr_count($actual, '<ol>'));
        self::assertStringContainsString("<ol>\n      <li>a</li>\n      <li>b</li>\n    </ol>", $actual);
    }

    public function testAnOffColumnSameTypeMarkerClosesTheAttachedItem(): void
    {
        $source = "- a\n  - +\n  + w1\n   - b\n";
        $expected = "<ul>\n  <li>a\n    <ul>\n      <li>+ w1</li>\n      <li>b</li>\n    </ul>\n  </li>\n</ul>";
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }
}
