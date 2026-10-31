<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A stacked first-block `+` takes a follower only at column 0 or at its opener line's column, with no exception
 * inside an item (markup-carve/carve#3421 item 5, ruling of 22 October).
 */
final class AStackedQuotePlusRefusesAFollowerAtTheItemColumnTest extends TestCase
{
    public function testTheFollowerAtTheItemColumnFoldsIntoTheRefusedPlus(): void
    {
        $this->assertSame(
            "<ul>\n  <li>\n    <ul>\n      <li>\n        <blockquote><p>+\nb</p></blockquote>\n      </li>\n    </ul>\n  </li>\n</ul>",
            trim((new CarveConverter())->convert("- - > +\n  b\n")),
        );
    }

    public function testAColumnZeroFollowerAttaches(): void
    {
        $this->assertSame(
            "<ul>\n  <li>\n    <ul>\n      <li>\n        <blockquote><p>+\nb</p></blockquote>\n      </li>\n    </ul>\n  </li>\n</ul>",
            trim((new CarveConverter())->convert("- - > +\nb\n")),
        );
    }
}
