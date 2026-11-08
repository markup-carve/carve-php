<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class SkippedDefinitionBeforeBareQuoteBlankTest extends TestCase
{
    public function testDefinitionStaysInQuoteBeforeBareBlank(): void
    {
        $source = "> [^n]: +\n> [^m]: d1\n>\n> - item\n\nsee[^n][^m]\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringStartsWith("<blockquote>\n  <ul>\n    <li>item</li>\n  </ul>\n</blockquote>", $html);
        self::assertStringContainsString('<p>d1<a href="#fnref2"', $html);
        self::assertStringNotContainsString('&gt;[^m]: d1', $html);
    }
}
