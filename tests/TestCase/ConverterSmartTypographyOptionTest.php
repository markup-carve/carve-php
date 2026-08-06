<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use MarkupCarve\Carve\Renderer\SmartTypographyMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The converter takes the documented `smartTypography` option.
 *
 * `docs/divergence-from-djot` documents a document-global switch with a call
 * per engine, and named this one `CarveConverter::create(smartTypography: false)`.
 * It did not exist: the constructor had no such parameter, so the documented
 * call raised `Unknown named parameter $smartTypography` (carve#560).
 *
 * Every renderer here already had `setSmartTypography()`; only the converter
 * had no way to reach it, so a host following the documentation got a fatal
 * rather than a configured converter.
 *
 * The bool spelling is what the documentation shows and what carve-js accepts;
 * the enum spelling is this engine's own vocabulary. Both are taken, the way
 * `safeMode` beside it already takes `SafeMode|bool|null`.
 */
class ConverterSmartTypographyOptionTest extends TestCase
{
    protected string $source = "He said \"hi\" -- really...\n";

    public function testTheDocumentedBooleanSpellingIsAccepted(): void
    {
        $off = (new CarveConverter(smartTypography: false))->convert($this->source);

        $this->assertStringContainsString('--', $off, 'the source run should survive with smart typography off');
        $this->assertStringContainsString('"hi"', $off, 'the straight quotes should survive too');
    }

    public function testTheEnumSpellingIsAccepted(): void
    {
        $off = (new CarveConverter(smartTypography: SmartTypographyMode::Source))->convert($this->source);

        $this->assertStringContainsString('--', $off);
    }

    public function testTheDefaultStillEmitsGlyphs(): void
    {
        // The control. An option that turned the feature off unconditionally
        // would satisfy every assertion above.
        $on = (new CarveConverter())->convert($this->source);

        // Asserted as the ABSENCE of the source runs rather than the presence
        // of a particular glyph: `--` resolves to an EN dash here, and pinning
        // which glyph each run maps to belongs to the smart-typography tests,
        // not to a test about whether the option is wired up.
        $this->assertStringNotContainsString('--', $on);
        $this->assertStringNotContainsString('"hi"', $on);
    }

    public function testTrueIsTheDefaultAndNotAnError(): void
    {
        $this->assertSame(
            (new CarveConverter())->convert($this->source),
            (new CarveConverter(smartTypography: true))->convert($this->source),
        );
    }

    /**
     * @return array<string, array{0: class-string<\MarkupCarve\Carve\Renderer\RendererInterface>}>
     */
    public static function nonHtmlRendererProvider(): array
    {
        return [
            'plain text' => [PlainTextRenderer::class],
            'ANSI' => [AnsiRenderer::class],
            'markdown' => [MarkdownRenderer::class],
        ];
    }

    /**
     * The option has to reach a renderer the CALLER built.
     *
     * The HTML-only options beside it (xhtml, safeMode, softBreakMode) are
     * documented as ignored when a pre-configured renderer is passed, and that
     * is right for them - they describe HTML. Smart typography is not an HTML
     * property: every renderer resolves the same node, and plain text and ANSI
     * are reached in this engine only by passing the renderer in. Ignoring it
     * there would leave the documented option silently ineffective on exactly
     * the targets the report named.
     *
     * @param class-string<\MarkupCarve\Carve\Renderer\RendererInterface> $rendererClass
     */
    #[DataProvider('nonHtmlRendererProvider')]
    public function testItReachesARendererTheCallerPassedIn(string $rendererClass): void
    {
        $off = (new CarveConverter(smartTypography: false, renderer: new $rendererClass()))->convert($this->source);
        $on = (new CarveConverter(renderer: new $rendererClass()))->convert($this->source);

        $this->assertStringContainsString('--', $off, "{$rendererClass} kept the glyph with the option off");
        $this->assertNotSame($on, $off, "{$rendererClass} rendered the same either way");
    }
}
