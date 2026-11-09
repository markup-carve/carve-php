<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class EscapedPipeCrossrefInTableCellTest extends TestCase
{
    public function testCellCrossrefReadsTheUnescapedPipe(): void
    {
        $source = "|</#\\|>|\n";
        $expected = "<table>\n  <tbody>\n    <tr><td>&lt;/#|&gt;</td></tr>\n  </tbody>\n</table>";

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
