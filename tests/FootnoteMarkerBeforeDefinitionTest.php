<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class FootnoteMarkerBeforeDefinitionTest extends TestCase
{
    use RuleATodo;

    public function testDuplicateDefinitionIsConsumedByFirstBlockMarker(): void
    {
        $html = CarveConverter::create()->convert("[^a]: +\n[^a]: +\n\nsee[^a]\n");

        self::assertStringContainsString('<li id="fn1">' . "\n" . '      <p><a', $html);
        self::assertStringNotContainsString('<p>+<a', $html);
    }

    public function testDistinctDefinitionStillEntersTheSymbolTable(): void
    {
        $html = CarveConverter::create()->convert("[^a]: +\n[^b]: +\n\nsee[^a] [^b]\n");

        self::assertStringContainsString('<li id="fn1">' . "\n" . '      <p><a', $html);
        self::assertStringContainsString('<li id="fn2">' . "\n" . '      <p>+<a', $html);
    }
}
