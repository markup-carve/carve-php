<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class WrappedMarkerAttributeContinuationTest extends TestCase
{
    public function testWrappedMarkerAttributeLeavesNoParagraphOpen(): void
    {
        $this->assertSame(
            "<ul>\n  <li></li>\n</ul>\n<p>tail</p>\n",
            (new CarveConverter())->convert("- {.a\n  .b}\ntail\n"),
        );
    }

    public function testWrappedBodyAttributeLeavesNoParagraphOpen(): void
    {
        $this->assertSame(
            "<ul>\n  <li>prose</li>\n</ul>\n<p>tail</p>\n",
            (new CarveConverter())->convert("- prose\n  {.a\n  .b}\ntail\n"),
        );
    }

    public function testUnclosedBraceRunRemainsLiteral(): void
    {
        $this->assertSame(
            "<ul>\n  <li>{.a\ntail</li>\n</ul>\n",
            (new CarveConverter())->convert("- {.a\ntail\n"),
        );
    }
}
