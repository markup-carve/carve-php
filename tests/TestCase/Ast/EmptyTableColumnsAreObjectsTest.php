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
        $encoded = $codec->encode((new BlockParser())->parse($source));

        self::assertSame('[{},{"align":"left"},{}]', json_encode($encoded['children'][0]['columns']));
        self::assertSame(
            json_encode($encoded, JSON_THROW_ON_ERROR),
            json_encode($codec->encode($codec->decodeJson(json_encode($encoded, JSON_THROW_ON_ERROR))), JSON_THROW_ON_ERROR),
        );
    }

    public function testEveryUnsetColumnPositionUsesAnObject(): void
    {
        $document = (new BlockParser())->parse("| a | b |\n");
        $document->getChildren()[0]->setColumns([[], []]);
        $encoded = (new AstCodec())->encode($document);

        self::assertSame('[{},{}]', json_encode($encoded['children'][0]['columns']));
    }
}
