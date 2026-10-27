<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\ListBlock;
use PHPUnit\Framework\TestCase;

class FlatOrderedListFastPathTest extends TestCase
{
    public function testInlineMarkupAndFollowingParagraph(): void
    {
        $source = "1. First `code` and *emphasis*\n2. Second [link](https://example.test)\n\nTail.\n";
        $converter = new CarveConverter();
        $document = $converter->parse($source);
        $list = $document->getChildren()[0];

        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertSame(ListBlock::TYPE_ORDERED, $list->getListType());
        $this->assertSame(1, $list->getStart());
        $this->assertTrue($list->isTight());
        $this->assertCount(2, $list->getChildren());
        $this->assertSame(
            preg_replace('/\n\z/', '', "<ol>\n  <li>First <code>code</code> and <strong>emphasis</strong></li>\n"
            . "  <li>Second <a href=\"https://example.test\">link</a></li>\n</ol>\n<p>Tail.</p>\n"),
            $converter->convert($source),
        );
    }

    public function testReferenceDefinitionInsideItemStillResolves(): void
    {
        $source = "- lead\n1. [t]: /t\n\nSee [t][].\n";

        $this->assertSame(
            preg_replace('/\n\z/', '', "<ul>\n  <li>lead</li>\n</ul>\n<ol>\n  <li></li>\n</ol>\n<p>See <a href=\"/t\">t</a>.</p>\n"),
            (new CarveConverter())->convert($source),
        );
    }
}
