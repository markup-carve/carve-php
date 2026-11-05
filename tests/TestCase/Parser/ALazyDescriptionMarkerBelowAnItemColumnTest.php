<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ALazyDescriptionMarkerBelowAnItemColumnTest extends TestCase
{
    public function testAShortMarkerFoldsIntoTheAttachedDescriptionParagraph(): void
    {
        $source = "- x\n\n  :: t\n  : +\n  r\n : b\n";
        $expected = "<ul>\n  <li><p>x</p>\n    <dl>\n      <dt>t</dt>\n      <dd>r\n: b</dd>\n    </dl>\n  </li>\n</ul>";
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testAnItemMarkerColumnStillEndsTheItem(): void
    {
        $output = (new CarveConverter())->convert("- x\n  :: t\n  : +\n  r\n: b\n");
        self::assertStringContainsString("</ul>\n<p>: b</p>", $output);
    }
}
