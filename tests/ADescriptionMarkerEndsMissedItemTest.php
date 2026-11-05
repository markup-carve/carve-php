<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ADescriptionMarkerEndsMissedItemTest extends TestCase
{
    public function testMarkerAtOuterItemColumnEndsLineBlockPayload(): void
    {
        $input = "- x\n  :: t\n  : - ::: |\n       - item\n: z\n";
        $expected = "<ul>\n  <li>x\n    <dl>\n      <dt>t</dt>\n      <dd>\n        <ul>\n          <li>\n            <div class=\"line-block\">\n              <p>&nbsp;- item</p>\n            </div>\n          </li>\n        </ul>\n      </dd>\n    </dl>\n  </li>\n</ul>\n<p>: z</p>";

        self::assertSame($expected, CarveConverter::create()->convert($input));
    }

    public function testMarkerAtDescriptionContentColumnStaysInside(): void
    {
        $html = CarveConverter::create()->convert("- x\n  :: t\n  : - ::: |\n       - item\n    : z\n");

        self::assertStringContainsString(': z</p>', $html);
        self::assertStringNotContainsString('</ul>\n<p>: z</p>', $html);
    }

    public function testTabAfterDescriptionSeparatorEndsItem(): void
    {
        $html = CarveConverter::create()->convert("- x\n  :: t\n  : - ::: |\n       - item\n: \tz\n");

        self::assertStringEndsWith("</ul>\n<p>: \tz</p>", $html);
    }
}
