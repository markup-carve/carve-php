<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

final class LineBlockNbspHtmlRoundTripTest extends TestCase
{
    public function testLineBlockSpacesKeepTheirNodeTypeAfterHtmlImport(): void
    {
        $source = "- ::: |\n    ```\n  :::\n  para\ntail\n";
        $html = CarveConverter::create()->convert($source);
        $imported = (new HtmlToCarve())->convert($html);
        $codec = new AstCodec();
        $original = $codec->encode(CarveConverter::create()->parse($source));
        $roundTrip = $codec->encode(CarveConverter::create()->parse($imported));

        self::assertSame(
            $original['children'][0]['items'][0]['children'][0],
            $roundTrip['children'][0]['items'][0]['children'][0],
        );
    }
}
