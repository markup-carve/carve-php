<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

final class LineBlockSynthesizedBreakPositionTest extends TestCase
{
    public function testReassembledBreakHasNoSourcePosition(): void
    {
        $parser = new BlockParser();
        $parser->enablePositionTracking();
        $source = "> ::: |\n> # h\n> x\nafter\n";
        $encoded = (new AstCodec())->encode($parser->parse($source));
        $children = $encoded['children'][0]['children'][0]['children'][0]['children'];

        self::assertSame('hard_break', $children[1]['type']);
        self::assertArrayNotHasKey('pos', $children[1]);
        self::assertSame('hard_break', $children[3]['type']);
        self::assertArrayNotHasKey('pos', $children[3]);
    }
}
