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

        self::assertStringContainsString("<dd>para\n- li</dd>", $html);
        self::assertStringNotContainsString('<ul>', $html);
    }

    public function testAnIndentedMarkerAtTheOuterBodyColumnOpensAList(): void
    {
        $html = (new CarveConverter())->convert(":: outer\n:  :: inner\n   : para\n   - li\n");

        self::assertStringContainsString('<dd>para</dd>', $html);
        self::assertStringContainsString("<ul>\n      <li>li</li>", $html);
    }
}
