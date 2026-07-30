<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The COMBINED bold-italic form is one construct, so it gets one style run and one
 * reset. Rendering it as nested strong-around-emphasis emitted a reset per level,
 * and the second is redundant since a reset clears every attribute -- which is why
 * the output was never visibly wrong and this surfaced only as a cross-engine
 * divergence.
 *
 * carve-rs carries bold-italic as a single kind and always emitted one
 * (carve#352, corpus 01-emphasis and both 128-bold-italic cases).
 */
class AnsiBoldItalicResetTest extends TestCase
{
    private CarveConverter $converter;

    private AnsiRenderer $renderer;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
        $this->renderer = new AnsiRenderer();
    }

    private function render(string $source): string
    {
        return $this->renderer->render($this->converter->parse($source));
    }

    public function testTheCombinedFormEmitsOneReset(): void
    {
        $out = $this->render("/*x*/\n");

        $this->assertSame(1, substr_count($out, AnsiRenderer::RESET));
        $this->assertStringContainsString(AnsiRenderer::BOLD . AnsiRenderer::ITALIC, $out);
    }

    public function testItEmitsOneResetMidWord(): void
    {
        $out = $this->render("a/*y*/b\n");

        $this->assertSame(1, substr_count($out, AnsiRenderer::RESET));
    }

    /**
     * The nested spelling has no combined marker, so it keeps the per-level styling
     * it has always had. Collapsing that too would be a different change, and a
     * wrong one.
     */
    public function testTheNestedSpellingStillNests(): void
    {
        $out = $this->render("*/x/*\n");

        $this->assertStringContainsString(AnsiRenderer::BOLD, $out);
        $this->assertStringContainsString(AnsiRenderer::ITALIC, $out);
    }

    public function testAnOrdinaryStrongIsUnaffected(): void
    {
        $out = $this->render("*x*\n");

        $this->assertSame(1, substr_count($out, AnsiRenderer::RESET));
        $this->assertStringNotContainsString(AnsiRenderer::ITALIC, $out);
    }
}
