<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

final class TabbedFootnoteLabelRoundTripTest extends TestCase
{
    public function testOwnAstIngestPreservesTabbedReferenceLabel(): void
    {
        $source = "see [^a\tb]\n\n[^a b]: note\n";
        $parser = new BlockParser();
        $parser->enablePositionTracking();
        $codec = new AstCodec();
        $json = $codec->encodeJson($parser->parse($source));
        $encoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame("a\tb", $encoded['children'][0]['children'][1]['label']);
        self::assertSame($json, $codec->encodeJson($codec->decodeJson($json)));
    }
}
