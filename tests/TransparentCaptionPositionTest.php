<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

final class TransparentCaptionPositionTest extends TestCase
{
    public function testAttachedCaptionUsesAuthoredPositionsAfterAnErasedDefinition(): void
    {
        $source = "*[A]: x\n\n{#t}\n| a |\n^ A #: c\n\nSee </#t>.\n";
        $parser = new BlockParser();
        $parser->enablePositionTracking();
        $ast = (new AstCodec())->encode($parser->parse($source));
        $children = $ast['children'] ?? null;
        self::assertIsArray($children);
        $table = $children[1] ?? null;
        self::assertIsArray($table);
        $caption = $table['caption'] ?? null;
        self::assertIsArray($caption);
        $text = $caption[3] ?? null;
        self::assertIsArray($text);
        self::assertSame(': c', $text['value'] ?? null);
        $pos = $text['pos'] ?? null;
        self::assertIsArray($pos);
        self::assertSame(25, $pos['startOffset'] ?? null);
        self::assertSame(28, $pos['endOffset'] ?? null);
        self::assertSame(5, $pos['startLine'] ?? null);
    }
}
