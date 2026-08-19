<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\RawInline;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

class EmptyRawInlineRefusesTest extends TestCase
{
    public function testWriterRefusesInsteadOfEmittingADifferentTree(): void
    {
        $document = new Document();
        $paragraph = new Paragraph();
        $paragraph->appendChild(new RawInline('', 'html'));
        $document->appendChild($paragraph);

        try {
            (new CarveRenderer())->render($document);
            self::fail('Expected an unspellable-source refusal.');
        } catch (SourceUnspellableException $exception) {
            self::assertSame('raw_inline', $exception->nodeType);
            self::assertSame('an empty raw inline has no Carve source spelling', $exception->reason);
        }
    }
}
