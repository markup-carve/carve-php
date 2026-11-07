<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

final class EmptyQuoteCommentSpanTest extends TestCase
{
    public function testInvisibleCommentDoesNotExtendEmptyQuote(): void
    {
        foreach (["> {% c %}\n^ cap\n", "> {% a longer comment %}\n\nnext\n"] as $source) {
            $ast = (new AstCodec())->encode((new BlockParser(trackPositions: true))->parse($source));
            self::assertSame([], $ast['children'][0]['children']);
            self::assertSame(2, $ast['children'][0]['pos']['endOffset']);
            self::assertSame(3, $ast['children'][0]['pos']['endColumn']);
        }
    }
}
