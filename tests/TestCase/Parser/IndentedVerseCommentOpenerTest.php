<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class IndentedVerseCommentOpenerTest extends TestCase
{
    public function testAnUnclosedIndentedOpenerHidesTheRestOfItsLineBlock(): void
    {
        self::assertSame("<div class=\"line-block\">\n\n</div>", trim((new CarveConverter())->convert("::: |\n %%%\n")));
    }

    public function testUpToThreeSpacesOpenAndFourStayVerseText(): void
    {
        foreach ([0, 1, 2, 3] as $spaces) {
            $source = "::: |\n" . str_repeat(' ', $spaces) . "%%%\nsecret\n%%%\n:::\n";
            self::assertSame("<div class=\"line-block\">\n\n</div>", trim((new CarveConverter())->convert($source)), $source);
        }
        self::assertStringContainsString('%%%', (new CarveConverter())->convert("::: |\n    %%%\n:::\n"));
    }
}
