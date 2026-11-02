<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class NestedDescriptionLazyMarkerTest extends TestCase
{
    public function testAFlushListMarkerFoldsIntoTheInnerDescription(): void
    {
        $html = (new CarveConverter())->convert(":: outer\n:  :: inner\n   : para\n- li\n");

        // A marker that misses both description bodies closes them (carve#4860).
        self::assertStringContainsString('<dd>para</dd>', $html);
        self::assertStringContainsString("</dl>\n<ul>\n  <li>li</li>\n</ul>", $html);
    }

    public function testAnIndentedMarkerAtTheOuterBodyColumnOpensAList(): void
    {
        $html = (new CarveConverter())->convert(":: outer\n:  :: inner\n   : para\n   - li\n");

        self::assertStringContainsString('<dd>para</dd>', $html);
        self::assertStringContainsString("<ul>\n      <li>li</li>", $html);
    }
}
