<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

class OneRoundTripOptionPreservesContainersTest extends TestCase
{
    public function testRoundTripModeWritesTheMarkdownCarrier(): void
    {
        $renderer = (new MarkdownRenderer())->setRoundTripMode(true);

        $this->assertTrue($renderer->isRoundTripMode());
        $this->assertStringContainsString('<!-- carve: ::: aside -->', $this->convert(new CarveConverter(renderer: $renderer)));
    }

    public function testMarkdownCarrierIsOffByDefault(): void
    {
        $this->assertStringNotContainsString('<!-- carve:', $this->convert(new CarveConverter(renderer: new MarkdownRenderer())));
    }

    public function testConverterConfiguresTheProvidedMarkdownRenderer(): void
    {
        $converter = new CarveConverter(roundTripMode: true, renderer: new MarkdownRenderer());

        $this->assertStringContainsString('<!-- carve: ::: aside -->', $this->convert($converter));
    }

    private function convert(CarveConverter $converter): string
    {
        return $converter->convert("::: aside\nText.\n:::\n");
    }
}
