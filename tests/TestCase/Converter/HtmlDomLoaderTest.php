<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlDomLoader;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class HtmlDomLoaderTest extends TestCase
{
    public function testImportPreservesTheCallersLibxmlErrorMode(): void
    {
        $original = libxml_use_internal_errors();
        try {
            foreach ([false, true] as $mode) {
                libxml_use_internal_errors($mode);

                $document = HtmlDomLoader::load('<div><p>text</div>');
                self::assertSame('text', $document->getElementsByTagName('p')->item(0)?->textContent);
                self::assertSame($mode, libxml_use_internal_errors());

                $converter = new HtmlToCarve();
                $converter->convertWithReport('<p>text</p>');
                $converter->convertToAst('<p>text</p>');
                self::assertSame($mode, libxml_use_internal_errors());
            }
        } finally {
            libxml_use_internal_errors($original);
        }
    }
}
