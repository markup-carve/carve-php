<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

class StackedListCarriersTest extends TestCase
{
    public function testAttributeAndStyleMarkersStackAboveTheList(): void
    {
        $source = "{.steps}\na. one\nb. two\n";
        $markdown = (new CarveConverter(renderer: new MarkdownRenderer(), roundTripMode: true))->convert($source);
        self::assertStringContainsString("<!-- carve: {.steps} -->\n<!-- carve: a. -->\n1. one", $markdown);
        self::assertSame($source, (new MarkdownToCarve())->convert($markdown));
    }

    public function testInterveningBlockTakesTheAttributes(): void
    {
        $markdown = "<!-- carve: {.steps} -->\nIntro.\n\n<!-- carve: a. -->\n1. one\n2. two\n";
        $result = (new MarkdownToCarve())->convertWithFidelityReport($markdown);
        self::assertSame("{.steps}\nIntro.\n\na. one\nb. two\n", $result->value);
        self::assertNotContains('carrier-markers-damaged', array_map(static fn ($diagnostic): string => $diagnostic->code, $result->diagnostics));
    }
}
