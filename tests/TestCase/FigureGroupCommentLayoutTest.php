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
        // Rule A: the open comment now takes the `~~~` line, leaving the group empty.
        $expected = "<figure class=\"carve-figure-group\">\n\n</figure>\n";
        self::assertSame(preg_replace('/\n\z/', '', $expected), CarveConverter::create()->convert("::: figure\n  %%%\n ~~~\n"));
        $expected = "<figure class=\"carve-figure-group\">\n  <pre><code></code></pre>\n</figure>\n";
        self::assertSame(preg_replace('/\n\z/', '', $expected), CarveConverter::create()->convert("::: figure\n ~~~\n"));
    }
}
