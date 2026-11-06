<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class AttachedItemAfterQuoteBlankTest extends TestCase
{
    public function testASecondQuotedContinuationReachesItsItem(): void
    {
        $source = "> - a\n> +\n> # h\n>\n> +\n> # h\n";
        $expected = "<blockquote>\n  <ul>\n    <li>a\n      <h1 id=\"h\">h</h1>\n      <h1 id=\"h-2\">h</h1>\n    </li>\n  </ul>\n</blockquote>";
        self::assertSame($expected, CarveConverter::create()->convert($source));
    }
}
