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

        $this->assertSame($source, CarveConverter::carve()->render($back));
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

    public function testBodyCellInheritsTheLastHeadRowMarkerWithoutAnAuthoredField(): void
    {
        $source = "|=> a |\n|=< b |\n| 1 |\n";
        $codec = new AstCodec();
        $encoded = $codec->encode((new CarveConverter())->parse($source));
        $bodyCell = $encoded['children'][0]['rows'][2]['cells'][0];
        $this->assertArrayNotHasKey('align', $bodyCell);

        $back = $codec->decode($encoded);
        $this->assertSame($source, CarveConverter::carve()->render($back));
        $this->assertSame((new CarveConverter())->convert($source), (new CarveConverter())->render($back));
    }

    public function testRowHeaderAfterBodyKeepsItsInheritedAlignment(): void
    {
        $source = "|=> a |\n| 1 |\n|= b |\n| 2 |\n";
        $codec = new AstCodec();
        $back = $codec->decode($codec->encode((new CarveConverter())->parse($source)));

        $this->assertSame($source, CarveConverter::carve()->render($back));
        $this->assertSame((new CarveConverter())->convert($source), (new CarveConverter())->render($back));
    }

    public function testInheritedVerticalAlignmentSurvivesBothExits(): void
    {
        $source = "|=>^ a |\n|= b |\n| 1 |\n";
        $document = (new CarveConverter())->parse($source);
        $codec = new AstCodec();
        $astBack = $codec->decode($codec->encode($document));
        $proseMirror = (new ProseMirrorRenderer())->render($document);
        $editorBack = (new ProseMirrorToCarve())->convert($proseMirror);

        foreach ([$astBack, $editorBack] as $back) {
            $this->assertSame($source, CarveConverter::carve()->render($back));
            $this->assertSame((new CarveConverter())->convert($source), (new CarveConverter())->render($back));
        }
    }

    public function testTwoColumnsKeepSeparateInheritedHeadRowAlignments(): void
    {
        $source = "|=> a |=< x |\n|= b |= y |\n| 1 | 2 |\n";
        $document = (new CarveConverter())->parse($source);
        $codec = new AstCodec();
        $astBack = $codec->decode($codec->encode($document));
        $proseMirror = (new ProseMirrorRenderer())->render($document);
        $editorBack = (new ProseMirrorToCarve())->convert($proseMirror);

        foreach ([$astBack, $editorBack] as $back) {
            $this->assertSame($source, CarveConverter::carve()->render($back));
            $this->assertSame((new CarveConverter())->convert($source), (new CarveConverter())->render($back));
        }
    }

    public function testVerticalOnlyHeadRowAlignmentReachesTheBody(): void
    {
        $source = "|=?^ a |\n|= b |\n| 1 |\n";
        $document = (new CarveConverter())->parse($source);
        $codec = new AstCodec();
        $astBack = $codec->decode($codec->encode($document));
        $proseMirror = (new ProseMirrorRenderer())->render($document);
        $editorBack = (new ProseMirrorToCarve())->convert($proseMirror);

        foreach ([$astBack, $editorBack] as $back) {
            $this->assertSame($source, CarveConverter::carve()->render($back));
            $this->assertSame((new CarveConverter())->convert($source), (new CarveConverter())->render($back));
        }
    }

    public function testAstDecodeUsesFinalHeadDefaults(): void
    {
        $cases = [
            "|A|B|\n|---|---|\n|=< C|= D|\n|c|d|\n",
            "{header-rows=1}\n|=> A|= B|\n|=< C|= D|\n|c|d|\n",
            "{header-rows=0}\n|=> A|= B|\n|c|d|\n",
            "{header-rows=0}\n|A|B|\n|:--|--:|\n|c|d|\n",
            "{aligns=right,center}\n|= A|= B|\n|c|d|\n",
            "{aligns=right,center}\n|A|B|\n|---|---|\n|c|d|\n",
            "{header-rows=3}\n|=>^ A|= B|\n|a|b|\n|=<v C|= D|\n|c|d|\n",
        ];

        $converter = new CarveConverter();
        $codec = new AstCodec();
        foreach ($cases as $source) {
            $document = $converter->parse($source);
            $wire = $codec->encode($document);
            $decoded = $codec->decode($wire);

            $this->assertSame($wire, $codec->encode($decoded), $source);
            $this->assertSame($converter->render($document), $converter->render($decoded), $source);
        }
    }
}
