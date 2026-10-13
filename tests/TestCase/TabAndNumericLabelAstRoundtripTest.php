<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class TabAndNumericLabelAstRoundtripTest extends TestCase
{
    public function testATabbedNoteLabelSurvivesJsonIngest(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/spec/tests/corpus/559-a-tab-inside-a-line-is-content-in-a-label-and-after-a-definition-marker-4.crv');
        $codec = new AstCodec();
        $tree = $codec->encode((new CarveConverter())->parse($source));
        self::assertSame("a\tb", $tree['children'][0]['children'][1]['label']);
        self::assertSame($tree, $codec->encode($codec->decodeJson(json_encode($tree, JSON_THROW_ON_ERROR))));
    }

    public function testNumericAbbreviationTermsStayStrings(): void
    {
        $codec = new AstCodec();
        $tree = $codec->encode((new CarveConverter())->parse("*[9]: nine\n\n9 here\n"));
        self::assertSame('9', $tree['children'][0]['abbr']);
        self::assertSame('9', $tree['children'][1]['children'][0]['abbr']);
        self::assertSame($tree, $codec->encode($codec->decodeJson(json_encode($tree, JSON_THROW_ON_ERROR))));
    }
}
