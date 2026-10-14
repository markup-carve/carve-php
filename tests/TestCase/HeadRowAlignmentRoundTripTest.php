<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;

class HeadRowAlignmentRoundTripTest extends TestCase
{
    public function testProseMirrorKeepsTheLastHeaderRowsColumnAlignment(): void
    {
        $source = "|=> a |\n|=< b |\n| 1 |\n";
        $document = (new CarveConverter())->parse($source);
        $proseMirror = (new ProseMirrorRenderer())->render($document);
        $back = (new ProseMirrorToCarve())->convert($proseMirror);

        $this->assertSame("|=> a |\n|=< b |\n|< 1 |\n", CarveConverter::carve()->render($back));
        $this->assertSame((new CarveConverter())->convert($source), (new CarveConverter())->render($back));
    }

    public function testAstDecodeKeepsAnInheritedHeaderAlignmentUnauthored(): void
    {
        $source = "|=> a |\n|= b |\n| 1 |\n";
        $codec = new AstCodec();
        $encoded = $codec->encode((new CarveConverter())->parse($source));
        $headerCell = $encoded['children'][0]['rows'][1]['cells'][0];
        $this->assertArrayNotHasKey('align', $headerCell);

        $back = $codec->decode($encoded);
        $this->assertSame($source, CarveConverter::carve()->render($back));
        $this->assertSame((new CarveConverter())->convert($source), (new CarveConverter())->render($back));
    }
}
