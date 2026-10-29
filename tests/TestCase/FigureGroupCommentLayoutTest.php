<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\TestCase;

final class FigureGroupCommentLayoutTest extends TestCase
{
    use RuleATodo;

    public function testCommentBeforeContentAddsNoEmptyHtmlLine(): void
    {
        $expected = "<figure class=\"carve-figure-group\">\n  <p>~~~</p>\n</figure>\n";
        self::assertSame(preg_replace('/\n\z/', '', $expected), CarveConverter::create()->convert("::: figure\n  %%%\n ~~~\n"));
        self::assertSame(preg_replace('/\n\z/', '', $expected), CarveConverter::create()->convert("::: figure\n ~~~\n"));
    }
}
