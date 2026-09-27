<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlDomLoader;
use PHPUnit\Framework\TestCase;

class HtmlDomLoaderBackendTest extends TestCase
{
    public function testTheRuntimeSelectsTheParser(): void
    {
        self::assertSame(PHP_VERSION_ID >= 80400, HtmlDomLoader::usesHtml5());
        $document = HtmlDomLoader::load('<ul><li><a id="target">one<li>two</ul>');
        self::assertCount(PHP_VERSION_ID >= 80400 ? 2 : 1, $document->getElementsByTagName('a'));
    }

    public function testBothParsersNormalizeCarriageReturns(): void
    {
        $document = HtmlDomLoader::fragment("<p>a\r\nb\rc</p>");
        self::assertSame("a\nb\nc", $document->getElementsByTagName('p')->item(0)?->textContent);
    }
}
