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
}
