<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class AnUnresolvedCaptionReturnsParagraphIndentTest extends TestCase
{
    public function testAllReturnedContinuationLinesUseParagraphIndentation(): void
    {
        $source = "![][r]\n^ p\n p\n  q\n    s\n";
        $this->assertSame(
            "<p>![][r]\n^ p\np\nq\ns</p>",
            trim((new CarveConverter())->convert($source)),
        );
    }

    public function testReturnedTextKeepsItsSourcePosition(): void
    {
        $source = "![][r]\n^ p\n p\n";
        $ast = (new AstCodec())->encode((new BlockParser(false, false, false, true))->parse($source));
        $children = $ast['children'][0]['children'];

        $this->assertSame('p', $children[4]['value']);
        $this->assertSame([12, 13], [
            $children[4]['pos']['startOffset'],
            $children[4]['pos']['endOffset'],
        ]);
    }
}
