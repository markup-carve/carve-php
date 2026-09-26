<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class AnUnattachedContinuationMarkerInNestedListTest extends TestCase
{
    public function testAnIndentedFollowerStillExtendsTheNestedParagraph(): void
    {
        $html = (new CarveConverter())->convert("- x\n  - L\n+\n  p\n");

        self::assertSame("<ul>\n  <li>x\n    <ul>\n      <li>L\np</li>\n    </ul>\n  </li>\n</ul>\n", $html);
    }

    public function testAPlusBelowTheNestedMarkerColumnIsText(): void
    {
        $html = (new CarveConverter())->convert("- x\n  - L\n +\n");

        self::assertSame("<ul>\n  <li>x\n    <ul>\n      <li>L\n+</li>\n    </ul>\n  </li>\n</ul>\n", $html);
    }

    public function testAPlusAfterAClosedNestedBlockIsStillText(): void
    {
        $html = (new CarveConverter())->convert("- x\n  - # h\n +\n");

        self::assertSame(
            "<ul>\n  <li>x\n    <ul>\n      <li>\n        <h1 id=\"h\">h</h1>\n      </li>\n    </ul>\n    +\n  </li>\n</ul>\n",
            $html,
        );
    }
}
