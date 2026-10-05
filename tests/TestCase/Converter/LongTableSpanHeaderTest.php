<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class LongTableSpanHeaderTest extends TestCase
{
    public function testLongRowspanRetainsItsHeaderOrigin(): void
    {
        $count = 2048;
        $html = '<table><tbody><tr><th rowspan="' . $count . '">R</th><td>A</td></tr>'
            . str_repeat('<tr><td>B</td></tr>', $count - 1)
            . '</tbody><tfoot><tr><td>F</td></tr></tfoot></table>';
        $ast = (new HtmlToCarve())->convertToAst($html);
        self::assertSame(1, $ast['children'][0]['rowGroups']['bodies'][0]['rowHeadColumns']);
        self::assertSame($count, $ast['children'][0]['rowGroups']['bodies'][0]['bodyRows']);
    }
}
