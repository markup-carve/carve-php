<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use PHPUnit\Framework\TestCase;

class AnUnspellableFenceClassStaysGenericTest extends TestCase
{
    public function testImporterKeepsDigitLeadingClassOnGenericDiv(): void
    {
        self::assertSame(
            "{.2col}\n:::\ny\n:::",
            trim((new HtmlToCarve())->convert('<div class="2col"><p>y</p></div>')),
        );
    }

    public function testParserRejectsDigitLeadingFenceWord(): void
    {
        self::assertNull((new FencedBlockParser())->parseDivFenceOpener('::: 2col'));
    }

    public function testParserStillAcceptsGrammarFenceWords(): void
    {
        self::assertNotNull((new FencedBlockParser())->parseDivFenceOpener('::: _2col'));
        self::assertNotNull((new FencedBlockParser())->parseDivFenceOpener('::: col2'));
    }
}
