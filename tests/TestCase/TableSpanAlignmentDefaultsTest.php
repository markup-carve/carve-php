<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class TableSpanAlignmentDefaultsTest extends TestCase
{
    public function testLeadingSpaceDoesNotStopAWholeCellSpan(): void
    {
        $source = "|a| <|\n";
        self::assertSame("| a | < |\n", CarveConverter::toCarve($source));
    }

    public function testHeaderCellSpanGlyphsStayLiteral(): void
    {
        $html = (new CarveConverter())->convert("|a|= <|\n|b|= ^|\n");
        self::assertStringContainsString('<tr><td>a</td><th scope="row">&lt;</th></tr>', $html);
        self::assertStringContainsString('<tr><td>b</td><th scope="row">^</th></tr>', $html);
    }

    public function testNativeHeaderDefaultStaysInItsStartingColumn(): void
    {
        $html = (new CarveConverter())->convert("|=> Wide|<|=~ Last|\n|a|b|c|\n");
        self::assertStringContainsString('<th scope="col" colspan="2" style="text-align: right;">Wide</th>', $html);
        self::assertStringContainsString('<tr><td style="text-align: right;">a</td><td>b</td><td style="text-align: center;">c</td></tr>', $html);
    }

    public function testCoveredDelimiterColumnKeepsItsOwnDefault(): void
    {
        $html = (new CarveConverter())->convert("|> Wide|<|~ Last|\n|:--|:--|:--|\n|a|b|c|\n");
        self::assertStringContainsString('<tr><td style="text-align: right;">a</td><td style="text-align: left;">b</td><td style="text-align: center;">c</td></tr>', $html);
    }

    public function testExplicitHeaderRowDoesNotWidenPlainMarkers(): void
    {
        $html = (new CarveConverter())->convert("{header-rows=1}\n|> Wide|<|~ Last|\n|a|b|c|\n");
        self::assertStringContainsString('<th scope="col" colspan="2" style="text-align: right;">Wide</th>', $html);
        self::assertStringContainsString('<tr><td>a</td><td>b</td><td>c</td></tr>', $html);
    }

    public function testVerticalDefaultStaysInTheStartingColumn(): void
    {
        $html = (new CarveConverter())->convert("|=>^ Wide|<|=~v Last|\n|a|b|c|\n");
        self::assertStringContainsString('<td style="text-align: right; vertical-align: top;">a</td><td>b</td><td style="text-align: center; vertical-align: bottom;">c</td>', $html);
    }
}
