<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A marked sibling below a hosted comment span's body column ends the span.
 * Its paragraph can then take the next unmarked lazy line (PART 0 S1, S4).
 */
final class AQuotedSpanEndedByASiblingLeavesItsLazyLineInTheQuoteTest extends TestCase
{
    public function testTheMinimalHostedSpanCase(): void
    {
        $source = "> - a\n>   %%%\n> - b\nx\n";
        $expected = "<blockquote>\n  <ul>\n    <li>a</li>\n    <li>b\nx</li>\n  </ul>\n</blockquote>";

        self::assertSame($expected, CarveConverter::create()->convert($source));
    }

    public function testTheSiblingParagraphKeepsItsLazyLine(): void
    {
        $source = "> i) :: t\n>    : ::: |\n>      %%%\n> - b\n : b\n";
        $expected = "<blockquote>\n  <ol type=\"i\" data-delim=\")\">\n    <li>\n      <dl>\n        <dt>t</dt>\n        <dd>\n          <div class=\"line-block\">\n\n          </div>\n        </dd>\n      </dl>\n    </li>\n  </ol>\n  <ul>\n    <li>b\n: b</li>\n  </ul>\n</blockquote>";

        self::assertSame($expected, CarveConverter::create()->convert($source));
    }
}
