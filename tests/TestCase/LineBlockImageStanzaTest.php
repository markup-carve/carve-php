<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\LineBlock;
use MarkupCarve\Carve\Node\Block\Paragraph;
use PHPUnit\Framework\TestCase;

final class LineBlockImageStanzaTest extends TestCase
{
    public function testLoneImageRemainsInlineInStanza(): void
    {
        $source = "::: |\n![a](i.png)\n:::\n";
        $converter = new CarveConverter();
        self::assertSame(preg_replace('/\n\z/', '', "<div class=\"line-block\">\n  <p><img src=\"i.png\" alt=\"a\"></p>\n</div>\n"), $converter->convert($source));

        $lineBlock = $converter->parse($source)->getChildren()[0];
        self::assertInstanceOf(LineBlock::class, $lineBlock);
        $stanza = $lineBlock->getChildren()[0];
        self::assertInstanceOf(Paragraph::class, $stanza);
        self::assertFalse($stanza->isBlockImage());
    }

    public function testImagesInSeparateStanzasKeepTheirParagraphs(): void
    {
        $source = "::: |\n![a](i.png)\n\n![b](j.png)\n:::\n";
        $html = (new CarveConverter())->convert($source);
        self::assertSame(2, substr_count($html, '<p><img'));
    }
}
