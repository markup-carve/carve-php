<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

final class ACellWrapBeforeVerbatimJoinHasPositionTest extends TestCase
{
    public function testEarlierWrapKeepsItsSourceMarker(): void
    {
        $source = "| a | b |\n+ c` | d |\n+ x | y |\n";
        $ast = (new AstCodec())->encode((new BlockParser(trackPositions: true))->parse($source));
        $wrap = $ast['children'][0]['rows'][0]['cells'][0]['children'][1];

        self::assertSame('cell_wrap', $wrap['type']);
        self::assertSame([
            'startLine' => 2,
            'endLine' => 2,
            'startColumn' => 1,
            'endColumn' => 2,
            'startOffset' => 10,
            'endOffset' => 11,
        ], $wrap['pos']);
    }
}
