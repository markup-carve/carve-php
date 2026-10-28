<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class FirstBlockTableBoundaryTest extends TestCase
{
    public function testSeparatorEndsAttachedTableBeforeContinuationRow(): void
    {
        $html = (new CarveConverter())->convert("- +\n||\n|-|\n+|\n");
        self::assertStringContainsString("</table>\n  </li>\n</ul>\n<p>+|</p>", $html);
    }

    public function testBodyRowKeepsItsContinuation(): void
    {
        $html = (new CarveConverter())->convert("- +\n|a|\n+ b|\n");
        self::assertStringContainsString('<td>a', $html);
        self::assertStringContainsString('b</td>', $html);
    }
}
