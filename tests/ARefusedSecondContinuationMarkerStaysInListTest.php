<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ARefusedSecondContinuationMarkerStaysInListTest extends TestCase
{
    public function testRefusedSecondMarkerIsItemTextBeforeSibling(): void
    {
        self::assertSame(
            "<ul>\n  <li>a\n    +\n  </li>\n  <li>b</li>\n</ul>\n",
            CarveConverter::create()->convert("- a\n+\n+\n- b\n"),
        );
    }

    public function testSecondMarkerCanAttachItsOwnFollower(): void
    {
        self::assertSame(
            "<ul>\n  <li>a\n    text\n  </li>\n</ul>\n",
            CarveConverter::create()->convert("- a\n+\n+\ntext\n"),
        );
    }
}
