<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class NumericAbbreviationTermTest extends TestCase
{
    public function testNumericTermStaysAStringOnTheAstWire(): void
    {
        $source = "*[dl]: definition list\n*[3D]: three dimensional\n*[9]: nine\n\nA dl, a 3D one, and 9.\n";
        $codec = new AstCodec();
        $json = $codec->encodeJson(CarveConverter::create()->parse($source));
        $encoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('9', $encoded['children'][2]['abbr']);
        self::assertSame($json, $codec->encodeJson($codec->decodeJson($json)));
    }
}
