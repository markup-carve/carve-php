<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class TableBesideNestedListInDivTest extends TestCase
{
    public function testTableAtNestedMarkerColumnIsSibling(): void
    {
        $html = (new CarveConverter())->convert("- :::\n   - a\n   ||\n");
        self::assertStringContainsString("</ul>\n      <table>", $html);
        self::assertStringContainsString('<td></td>', $html);
    }

    public function testTableAtItemContentColumnBelongsInsideItem(): void
    {
        $html = (new CarveConverter())->convert("- :::\n   - a\n     ||\n");
        self::assertStringContainsString("<li>a\n", $html);
        self::assertStringContainsString('<table>', $html);
    }

    public function testTableOneColumnPastNestedMarkerIsSibling(): void
    {
        $html = (new CarveConverter())->convert("- :::\n   - a\n    ||\n");
        self::assertStringContainsString("</ul>\n      <table>", $html);
    }

    public function testTableBeforeOrderedItemContentColumnIsSibling(): void
    {
        $html = (new CarveConverter())->convert("- :::\n   1. a\n     ||\n");
        self::assertStringContainsString("</ol>\n      <table>", $html);
    }
}
