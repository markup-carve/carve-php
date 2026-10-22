<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\ListBlock;
use PHPUnit\Framework\TestCase;

final class PlainLooseListTest extends TestCase
{
    public function testBlankSeparatedPlainItemsKeepTheirAstAndHtml(): void
    {
        $source = "- first item\n\n- second item\n";
        $converter = new CarveConverter();
        $document = $converter->parse($source);
        $list = $document->getChildren()[0];

        self::assertInstanceOf(ListBlock::class, $list);
        self::assertFalse($list->isTight());
        self::assertCount(2, $list->getChildren());

        $expected = "<ul>\n  <li><p>first item</p></li>\n  <li><p>second item</p></li>\n</ul>\n";
        self::assertSame($expected, $converter->render($document));

        $positioned = new CarveConverter();
        $positioned->getParser()->enablePositionTracking();
        self::assertSame($expected, $positioned->render($positioned->parse($source)));
    }
}
