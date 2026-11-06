<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class BelowColumnCommentInChildContainerTest extends TestCase
{
    public function testBelowColumnCommentLineDoesNotCloseItsItem(): void
    {
        $source = "- ::: box\n  %%%%\n%% j\n  x\n";
        $html = (new CarveConverter())->convert($source);

        self::assertSame("<ul>\n  <li>\n    <div class=\"box\">\n\n    </div>\n  </li>\n</ul>", $html);
    }
}
