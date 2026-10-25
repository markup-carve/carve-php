<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class DisplayMathTrailingAttrsCaptionTest extends TestCase
{
    public function testAdjacentCaptionKeepsTheMathId(): void
    {
        $source = "$$`E=mc^2`{#eq-1}\n^ cap\n";
        $expected = "<figure>\n  <p><span class=\"math display\" id=\"eq-1\" role=\"math\">\\[E=mc^2\\]</span></p>\n  <figcaption>cap</figcaption>\n</figure>";
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testCaptionAcrossOneBlankLineKeepsMathAttributes(): void
    {
        $source = "$$`x+y`{.boxed data-k=v}\n\n^ Equation #: Sum\n";
        $expected = "<figure>\n  <p><span class=\"math display boxed\" data-k=\"v\" role=\"math\">\\[x+y\\]</span></p>\n  <figcaption>Equation 1: Sum</figcaption>\n</figure>";
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
