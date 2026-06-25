<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Extension;

use Carve\CarveConverter;
use Carve\Extension\ColorSwatchExtension;
use PHPUnit\Framework\TestCase;

class ColorSwatchExtensionTest extends TestCase
{
    protected function convert(string $djot): string
    {
        $converter = new CarveConverter();
        $converter->addExtension(new ColorSwatchExtension());

        return $converter->convert($djot);
    }

    public function testInlineRendersHexSwatch(): void
    {
        $this->assertSame(
            '<p><span class="swatch"><span class="swatch-chip" style="background-color:#ff8800"></span> #ff8800</span></p>',
            trim($this->convert(':color[#ff8800]')),
        );
    }

    public function testInlineRendersNamedSwatch(): void
    {
        $this->assertStringContainsString(
            '<span class="swatch"><span class="swatch-chip" style="background-color:rebeccapurple"></span> rebeccapurple</span>',
            $this->convert(':color[rebeccapurple]'),
        );
    }

    public function testInlineRendersFunctionSwatch(): void
    {
        $this->assertStringContainsString(
            '<span class="swatch"><span class="swatch-chip" style="background-color:rgb(248,81,73)"></span> rgb(248,81,73)</span>',
            $this->convert(':color[rgb(248,81,73)]'),
        );
    }

    public function testInlineMergesClassesAndAttributes(): void
    {
        $this->assertSame(
            '<p><span class="swatch y" id="x"><span class="swatch-chip" style="background-color:#fff"></span> #fff</span></p>',
            trim($this->convert(':color[#fff]{#x .y}')),
        );
    }

    public function testInlineFlattensParsedValueText(): void
    {
        $this->assertStringContainsString(
            '<span class="swatch"><span class="swatch-chip" style="background-color:#fff"></span> #fff</span>',
            $this->convert(':color[#fff]'),
        );
    }

    public function testInlineDefersInvalidColorToGenericFallback(): void
    {
        $html = $this->convert(':color[nope!]');

        $this->assertStringContainsString('<span class="ext-color">nope!</span>', $html);
        $this->assertStringNotContainsString('swatch-chip', $html);
        $this->assertStringNotContainsString('background-color:', $html);
    }

    public function testInlineDefersInjectionAttemptToGenericFallback(): void
    {
        $html = $this->convert(':color[red;}x{}]');

        $this->assertStringContainsString('<span class="ext-color">red;}x{}</span>', $html);
        $this->assertStringNotContainsString('swatch-chip', $html);
        $this->assertStringNotContainsString('background-color:', $html);
    }

    public function testInlineFallsBackToExtColorWithoutExtension(): void
    {
        $converter = new CarveConverter();

        $this->assertStringContainsString(
            '<span class="ext-color">red</span>',
            $converter->convert(':color[red]'),
        );
    }
}
