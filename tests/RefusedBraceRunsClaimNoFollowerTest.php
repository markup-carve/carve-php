<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class RefusedBraceRunsClaimNoFollowerTest extends TestCase
{
    public function testHeadingAfterRefusedRootRunInterrupts(): void
    {
        $source = "{.x\n.y}\n# H\n";
        $expected = "<p>{.x\n.y}</p>\n<section id=\"H\">\n  <h1>H</h1>\n</section>";

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testNestedListAfterRefusedItemRunOpensNormally(): void
    {
        $source = "- {.x\n }\n  - q\n";
        $expected = "<ul>\n  <li>{.x\n}\n    <ul>\n      <li>q</li>\n    </ul>\n  </li>\n</ul>";

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testLazyCommentContinuationIsLiteralInNestedItem(): void
    {
        $source = "- a\n  - b\n{% c\n d %}\n tail\n";
        $expected = "<ul>\n  <li>a\n    <ul>\n      <li>b\n{% c\nd %}\ntail</li>\n    </ul>\n  </li>\n</ul>";

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testPrefixedMarkerLineRunStillApplies(): void
    {
        $source = "> {.x\n>   .y}\n> # H\n";
        $expected = "<blockquote>\n  <h1 class=\"x y\" id=\"H\">H</h1>\n</blockquote>";

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
