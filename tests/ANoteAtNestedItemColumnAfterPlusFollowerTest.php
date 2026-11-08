<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class ANoteAtNestedItemColumnAfterPlusFollowerTest extends TestCase
{
    public function testAThirdDefinitionAtTheItemColumnRegistersAfterThePreviousNotesPlusFollower(): void
    {
        $source = "- top\n  - [^a]: x\n    [^b]: +\n    flush1\n    [^c]: y\n\nsee[^a][^b][^c]\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString('<li></li>', $html);
        self::assertStringContainsString('<li id="fn2">', $html);
        self::assertStringContainsString('<p>flush1', $html);
        self::assertStringContainsString('<li id="fn3">', $html);
        self::assertStringContainsString('<p>y', $html);
        self::assertStringNotContainsString('[^c]: y</li>', $html);
    }

    public function testAnOddColumnAndQuoteStillUseTheAuthoredItemColumn(): void
    {
        $source = "> - top\n>    - [^a]: x\n>      [^b]: +\n>      flush\n>      [^c]: y\n\nsee[^a][^b][^c]\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString('<li id="fn3">', $html);
        self::assertStringContainsString('<p>y', $html);
        self::assertStringNotContainsString('[^c]: y</li>', $html);
    }
}
