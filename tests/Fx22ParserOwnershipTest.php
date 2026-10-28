<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class Fx22ParserOwnershipTest extends TestCase
{
    public function testNestedFirstBlockMarkerKeepsItsFollower(): void
    {
        $html = (new CarveConverter())->convert("- parent\n  - +\n  +\n  ||\n");

        self::assertStringContainsString("<li>\n        <table>", $html);
        self::assertStringNotContainsString('<li>+</li>', $html);
    }

    public function testQuotedCodeIncludeEndsItsOwnParagraph(): void
    {
        $html = (new CarveConverter())->convert("> > {{ f.txt @code }}\n> after\n>\n");

        self::assertStringContainsString("</p>\n    <p>after</p>", $html);
        self::assertStringNotContainsString("}}\nafter", $html);
    }

    public function testLastAbbreviationDefinitionControlsEarlierUse(): void
    {
        $html = (new CarveConverter())->convert("*[A]: one\nA\n*[A]: two\n");

        self::assertSame('<p><abbr title="two">A</abbr></p>', $html);
    }

    public function testQuoteDirectionSeesClosingSourceDelimiter(): void
    {
        $html = (new CarveConverter())->convert("*/\"*\n");

        self::assertSame('<p><strong>/“</strong></p>', $html);
    }
}
