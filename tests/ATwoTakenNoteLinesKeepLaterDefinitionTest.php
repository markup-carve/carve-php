<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class ATwoTakenNoteLinesKeepLaterDefinitionTest extends TestCase
{
    public function testALaterDefinitionAtTheNestedItemColumnSurvivesTwoTakenLines(): void
    {
        $source = "- top\n  - [^a]: +\n      flush0\n    [^b]: +\n    flush1\n    [^c]: x2\n\nsee[^a][^b][^c]\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString('<li></li>', $html);
        self::assertStringContainsString('<li id="fn1">', $html);
        self::assertStringContainsString('<li id="fn2">', $html);
        self::assertStringContainsString('<li id="fn3">', $html);
        self::assertStringContainsString('<p>x2', $html);
        self::assertStringNotContainsString('[^c]: x2</li>', $html);
    }
}
