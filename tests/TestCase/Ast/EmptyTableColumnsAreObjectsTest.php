<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

final class EmptyTableColumnsAreObjectsTest extends TestCase
{
    public function testUnsetColumnPositionsUseObjectsOnTheWire(): void
    {
        $source = "{aligns=,left,}\n|= Wide|<|= Last|\n|a|b|c|\n";
        $codec = new AstCodec();
        $document = (new BlockParser())->parse($source);
        $encoded = $codec->encode($document);
        $wire = json_decode($codec->encodeJson($document), false, 512, JSON_THROW_ON_ERROR);

        self::assertSame('[{},{"align":"left"},{}]', json_encode($wire->children[0]->columns));
        self::assertSame($encoded, $codec->encode($codec->decode($encoded)));
    }

    public function testEveryUnsetColumnPositionUsesAnObject(): void
    {
        $document = (new BlockParser())->parse("| a | b |\n");
        $document->getChildren()[0]->setColumns([[], []]);
        $wire = json_decode((new AstCodec())->encodeJson($document), false, 512, JSON_THROW_ON_ERROR);

        self::assertSame('[{},{}]', json_encode($wire->children[0]->columns));
    }
}
