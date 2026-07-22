<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use InvalidArgumentException;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\ColorSwatchExtension;
use MarkupCarve\Carve\SafeMode;
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
            '<p><span id="x" class="swatch y"><span class="swatch-chip" style="background-color:#fff"></span> #fff</span></p>',
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

    public function testContrastLabelUsesWhiteTextForDarkHex(): void
    {
        $this->assertSame(
            '<p><span class="swatch-label" style="background:#0d1117;color:#fff">#0d1117</span></p>',
            trim($this->convert(':color[#0d1117]{contrast}')),
        );
    }

    public function testContrastLabelUsesBlackTextForMidHex(): void
    {
        $this->assertSame(
            '<p><span class="swatch-label" style="background:#58a6ff;color:#000">#58a6ff</span></p>',
            trim($this->convert(':color[#58a6ff]{contrast}')),
        );
    }

    public function testContrastLabelUsesBlackTextForLightHex(): void
    {
        $this->assertSame(
            '<p><span class="swatch-label" style="background:#f0f6fc;color:#000">#f0f6fc</span></p>',
            trim($this->convert(':color[#f0f6fc]{contrast}')),
        );
    }

    public function testContrastLabelComputesRgbFunction(): void
    {
        $this->assertSame(
            '<p><span class="swatch-label" style="background:rgb(240,246,252);color:#000">rgb(240,246,252)</span></p>',
            trim($this->convert(':color[rgb(240,246,252)]{contrast}')),
        );
    }

    public function testContrastLabelMergesClassesAndAttributesAndConsumesContrast(): void
    {
        $this->assertSame(
            '<p><span class="swatch-label x" id="y" style="background:#fff;color:#000">#fff</span></p>',
            trim($this->convert(':color[#fff]{contrast .x #y}')),
        );
    }

    public function testContrastDeclinesFullyTransparentColor(): void
    {
        $this->assertSame(
            '<p><span class="swatch"><span class="swatch-chip" style="background-color:#00000000"></span> #00000000</span></p>',
            trim($this->convert(':color[#00000000]{contrast}')),
        );
    }

    public function testContrastLetsAuthorStyleWinWithoutDuplicateStyle(): void
    {
        $this->assertSame(
            '<p><span style="opacity:0.5" class="swatch-label">#fff</span></p>',
            trim($this->convert(':color[#fff]{contrast style="opacity:0.5"}')),
        );
    }

    public function testContrastFallsBackToNormalSwatchForNamedColorAndConsumesContrast(): void
    {
        $this->assertSame(
            '<p><span class="swatch"><span class="swatch-chip" style="background-color:rebeccapurple"></span> rebeccapurple</span></p>',
            trim($this->convert(':color[rebeccapurple]{contrast}')),
        );
    }

    public function testInlineDefersInvalidColorToGenericFallback(): void
    {
        $html = $this->convert(':color[nope!]');

        $this->assertStringContainsString('<span class="ext-color">nope!</span>', $html);
        $this->assertStringNotContainsString('swatch-chip', $html);
        $this->assertStringNotContainsString('background-color:', $html);
    }

    public function testInlineDefersBarewordThatIsNotANamedColor(): void
    {
        // A pure-alphabetic value is only a color if it is an actual CSS named
        // color; arbitrary words must defer to the generic fallback.
        $html = $this->convert(':color[banana]');

        $this->assertStringContainsString('<span class="ext-color">banana</span>', $html);
        $this->assertStringNotContainsString('swatch-chip', $html);
        $this->assertStringNotContainsString('background-color:', $html);
    }

    public function testInlineRendersNamedColorCaseInsensitively(): void
    {
        $html = $this->convert(':color[DarkSlateGray]');

        $this->assertStringContainsString('background-color:DarkSlateGray', $html);
        $this->assertStringContainsString('swatch-chip', $html);
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

    protected function convertWith(ColorSwatchExtension $ext, string $djot): string
    {
        $converter = new CarveConverter();
        $converter->addExtension($ext);

        return trim($converter->convert($djot));
    }

    public function testPositionAfterPutsChipAfterTheValue(): void
    {
        $this->assertSame(
            '<p><span class="swatch">#3b82f6 <span class="swatch-chip" style="background-color:#3b82f6"></span></span></p>',
            $this->convertWith(new ColorSwatchExtension(position: 'after'), ':color[#3b82f6]'),
        );
    }

    public function testPositionNoneRendersChipOnlyWithValueAsTitle(): void
    {
        $this->assertSame(
            '<p><span class="swatch swatch-chip-only" title="#3b82f6"><span class="swatch-chip" style="background-color:#3b82f6"></span></span></p>',
            $this->convertWith(new ColorSwatchExtension(position: 'none'), ':color[#3b82f6]'),
        );
    }

    public function testRoundShapeAddsModifierClass(): void
    {
        $this->assertStringContainsString(
            '<span class="swatch-chip swatch-chip-round" style="background-color:#3b82f6">',
            $this->convertWith(new ColorSwatchExtension(shape: 'round'), ':color[#3b82f6]'),
        );
    }

    public function testRingShapeUsesBorderColorInsteadOfBackground(): void
    {
        $html = $this->convertWith(new ColorSwatchExtension(shape: 'ring'), ':color[#3b82f6]');

        $this->assertStringContainsString('swatch-chip-ring', $html);
        $this->assertStringContainsString('style="border-color:#3b82f6"', $html);
        $this->assertStringNotContainsString('background-color:#3b82f6', $html);
    }

    public function testTintPaintsAColorMixBehindTheSwatch(): void
    {
        $html = $this->convertWith(new ColorSwatchExtension(tint: true), ':color[#3b82f6]');

        $this->assertStringContainsString('class="swatch swatch-tint"', $html);
        $this->assertStringContainsString(
            'style="background-color:color-mix(in srgb, #3b82f6 12%, transparent)"',
            $html,
        );
    }

    public function testTintStyleSurvivesStrictSafeModeButAuthorStyleDoesNot(): void
    {
        // The tint `style` is extension-generated (trusted), so a strict safe
        // mode that blocks `style` must NOT strip it - it is applied after the
        // author-attribute filtering. An authored `style` is still filtered.
        $converter = new CarveConverter(safeMode: SafeMode::strict());
        $converter->addExtension(new ColorSwatchExtension(tint: true));

        $html = trim($converter->convert(':color[#fff]{style="opacity:0.1"}'));
        $this->assertStringContainsString(
            'style="background-color:color-mix(in srgb, #fff 12%, transparent)"',
            $html,
        );
        $this->assertStringNotContainsString('opacity:0.1', $html);
    }

    public function testRevealWrapsValueAndMakesSwatchFocusable(): void
    {
        $this->assertSame(
            '<p><span class="swatch swatch-reveal" tabindex="0"><span class="swatch-chip" style="background-color:#3b82f6"></span> <span class="swatch-val">#3b82f6</span></span></p>',
            $this->convertWith(new ColorSwatchExtension(reveal: true), ':color[#3b82f6]'),
        );
    }

    public function testRevealWithPositionAfterWrapsValueBeforeChip(): void
    {
        $this->assertSame(
            '<p><span class="swatch swatch-reveal" tabindex="0"><span class="swatch-val">#3b82f6</span> <span class="swatch-chip" style="background-color:#3b82f6"></span></span></p>',
            $this->convertWith(new ColorSwatchExtension(position: 'after', reveal: true), ':color[#3b82f6]'),
        );
    }

    public function testRevealIsIgnoredWhenPositionIsNone(): void
    {
        // `none` already hides the value (surfaced via title); reveal is a no-op
        // and must not add the swatch-reveal class, a wrapper, or tabindex.
        $this->assertSame(
            '<p><span class="swatch swatch-chip-only" title="#3b82f6"><span class="swatch-chip" style="background-color:#3b82f6"></span></span></p>',
            $this->convertWith(new ColorSwatchExtension(position: 'none', reveal: true), ':color[#3b82f6]'),
        );
    }

    public function testDefaultOutputIsUnchanged(): void
    {
        $this->assertSame(
            '<p><span class="swatch"><span class="swatch-chip" style="background-color:#3b82f6"></span> #3b82f6</span></p>',
            $this->convertWith(new ColorSwatchExtension(), ':color[#3b82f6]'),
        );
    }

    public function testInvalidPositionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ColorSwatchExtension(position: 'sideways');
    }

    public function testInvalidShapeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ColorSwatchExtension(shape: 'triangle');
    }
}
