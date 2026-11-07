<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class AClosedAttachedColonRestoresItsHostTest extends TestCase
{
    public function testTheItemKeepsItsFollowerAfterTheCloser(): void
    {
        self::assertSame(
            "<ul>\n  <li>\n    <div class=\"x\">\n      <p>a</p>\n    </div>\n    <ul>\n      <li>y</li>\n    </ul>\n  </li>\n</ul>",
            (new CarveConverter())->convert("- +\n::: x\n\n  a\n  :::\n\n  - y\n"),
        );
    }
}
