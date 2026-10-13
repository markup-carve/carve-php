<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class QuotedListHtmlImportTest extends TestCase
{
    public function testListAtStartOfQuoteDoesNotGainAnEmptyLine(): void
    {
        foreach (["> - a\n", "> - :: term\n>   : definition\n"] as $source) {
            $html = (new CarveConverter())->convert($source);
            foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
                self::assertSame($source, (new HtmlToCarve(importMode: $mode))->convert($html));
            }
        }
    }
}
