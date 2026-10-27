<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class WrappedTightListFastPathTest extends TestCase
{
    public function testWrappedItemsKeepInlineMarkupAndFollowingParagraph(): void
    {
        $source = "- First *emphasis*\n  continues [link](https://example.test)\n- Second `code`\n  and more text\n\nTail.\n";
        $converter = new CarveConverter();
        $list = $converter->parse($source)->getChildren()[0];

        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertSame(ListBlock::TYPE_BULLET, $list->getListType());
        $this->assertTrue($list->isTight());
        $this->assertCount(2, $list->getChildren());
        $this->assertSame(
            preg_replace('/\n\z/', '', "<ul>\n  <li>First <strong>emphasis</strong>\ncontinues <a href=\"https://example.test\">link</a></li>\n"
            . "  <li>Second <code>code</code>\nand more text</li>\n</ul>\n<p>Tail.</p>\n"),
            $converter->convert($source),
        );
    }

    public function testNestedOrderedListUsesTheCollector(): void
    {
        $source = "- alpha\n  1. nested\n- gamma\n";

        $this->assertSame(
            preg_replace('/\n\z/', '', "<ul>\n  <li>alpha\n    <ol>\n      <li>nested</li>\n    </ol>\n  </li>\n  <li>gamma</li>\n</ul>\n"),
            (new CarveConverter())->convert($source),
        );
    }

    public function testInlineLedItemsKeepTheGeneralParsersAst(): void
    {
        $sources = [
            "- *Parse* reads a document.\n- *Render* writes HTML.\n",
            "- `code` starts an item.\n  `next` continues it.\n- Second item.\n\nTail.\n",
            "- First item.\n  Continues here.\n- `code` in the next item.\n  More text.\n",
            "- ```\n  fenced content\n  ```\n- Next item.\n",
        ];
        $codec = new AstCodec();
        foreach ($sources as $source) {
            $optimized = (new CarveConverter())->parse($source);
            $general = (new CarveConverter(parser: new class extends BlockParser {
            }))->parse($source);
            self::assertSame($codec->encodeJson($general), $codec->encodeJson($optimized), $source);
        }
    }
}
