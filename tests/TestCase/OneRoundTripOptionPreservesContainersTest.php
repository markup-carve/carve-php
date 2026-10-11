<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

class OneRoundTripOptionPreservesContainersTest extends TestCase
{
    public function testRoundTripAloneWritesTheCarrier(): void
    {
        $renderer = (new MarkdownRenderer())->setRoundTripMode(true);
        $this->assertTrue($renderer->isRoundTripMode());
        $this->assertFalse($renderer->getCarryMarkers());
        $this->assertCarrier($renderer);
    }

    public function testDisablingTheAliasDoesNotDisableRoundTripInEitherOrder(): void
    {
        $this->assertCarrier((new MarkdownRenderer())->setCarryMarkers(false)->setRoundTripMode(true));
        $this->assertCarrier((new MarkdownRenderer())->setRoundTripMode(true)->setCarryMarkers(false));
    }

    public function testDisablingRoundTripDoesNotDisableTheAliasInEitherOrder(): void
    {
        $this->assertCarrier((new MarkdownRenderer())->setRoundTripMode(false)->setCarryMarkers(true));
        $this->assertCarrier((new MarkdownRenderer())->setCarryMarkers(true)->setRoundTripMode(false));
    }

    public function testConverterConfiguresTheProvidedMarkdownRenderer(): void
    {
        $converter = new CarveConverter(roundTripMode: true, renderer: new MarkdownRenderer());
        $this->assertStringContainsString('<!-- carve: ::: aside -->', $converter->convert("::: aside\nText.\n:::\n"));
    }

    public function testConverterKeepsPreconfiguredRoundTripEnabled(): void
    {
        $this->assertCarrier((new MarkdownRenderer())->setRoundTripMode(true));
    }

    private function assertCarrier(MarkdownRenderer $renderer): void
    {
        $converter = new CarveConverter(renderer: $renderer);
        $this->assertStringContainsString('<!-- carve: ::: aside -->', $converter->convert("::: aside\nText.\n:::\n"));
    }
}
