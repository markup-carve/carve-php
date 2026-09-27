<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class EachListItemStartsAFreshMarkdownListRunTest extends TestCase
{
    public function testNestedListsInDifferentItemsKeepTheirMarker(): void
    {
        $source = "- a\n  - b\n- - c\n  - d\n";

        $this->assertSame($source, CarveConverter::markdown()->convert($source));
    }

    public function testAdjacentListsInOneItemStillAlternate(): void
    {
        $source = "- x\n  - a\n\n\n\n  - b\n";

        $this->assertSame("- x\n  - a\n  * b\n", CarveConverter::markdown()->convert($source));
    }
}
