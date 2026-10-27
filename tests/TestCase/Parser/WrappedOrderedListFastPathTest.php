<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\ListBlock;
use PHPUnit\Framework\TestCase;

class WrappedOrderedListFastPathTest extends TestCase
{
    public function testWrappedItemsKeepInlineMarkupAndFollowingParagraph(): void
    {
        $source = "1. *First* text\n   continues [link](https://example.test)\n2. Second\n   wraps here\n\nTail.\n";
        $converter = new CarveConverter();
        $list = $converter->parse($source)->getChildren()[0];

        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertSame(ListBlock::TYPE_ORDERED, $list->getListType());
        $this->assertTrue($list->isTight());
        $this->assertCount(2, $list->getChildren());
        $this->assertSame(
            preg_replace('/\n\z/', '', "<ol>\n  <li><strong>First</strong> text\ncontinues <a href=\"https://example.test\">link</a></li>\n"
            . "  <li>Second\nwraps here</li>\n</ol>\n<p>Tail.</p>\n"),
            $converter->convert($source),
        );
    }

    public function testNestedMarkerUsesTheCollector(): void
    {
        $source = "1. alpha\n   - nested\n2. gamma\n";

        $this->assertSame(
            preg_replace('/\n\z/', '', "<ol>\n  <li>alpha\n    <ul>\n      <li>nested</li>\n    </ul>\n  </li>\n  <li>gamma</li>\n</ol>\n"),
            (new CarveConverter())->convert($source),
        );
    }

    public function testThematicBreakItemUsesTheCollector(): void
    {
        $source = "1. ***\n2. next\n";

        $this->assertSame(
            preg_replace('/\n\z/', '', "<ol>\n  <li>\n    <hr>\n  </li>\n  <li>next</li>\n</ol>\n"),
            (new CarveConverter())->convert($source),
        );
    }
}
