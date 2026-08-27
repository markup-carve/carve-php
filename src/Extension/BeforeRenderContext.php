<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\RendererInterface;
use MarkupCarve\Carve\Renderer\RenderMode;
use MarkupCarve\Carve\Renderer\SmartTypographyMode;
use MarkupCarve\Carve\SafeMode;

/**
 * The context handed to {@see BeforeRenderExtensionInterface::beforeRender()}.
 */
final class BeforeRenderContext
{
    /**
     * @param array<string, string> $symbols Trusted HTML replacements for `:name:` symbols.
     * @param \MarkupCarve\Carve\Renderer\SmartTypographyMode $smartTypography Whether smart typography resolves to glyphs.
     * @param \MarkupCarve\Carve\SafeMode|null $safeMode The configured safe mode, or null when none is set.
     * @param array<string, \Closure(string): string> $staticRenderers Build-time renderers keyed by css class (`math` also takes a display flag).
     * @param string $effectiveMode The effective mode for the target: a {@see RenderMode} constant.
     * @param bool $targetIsHtml Whether the final render target is HTML.
     */
    public function __construct(
        private readonly array $symbols,
        private readonly SmartTypographyMode $smartTypography,
        private readonly ?SafeMode $safeMode,
        private readonly array $staticRenderers,
        private readonly string $effectiveMode,
        private readonly bool $targetIsHtml,
    ) {
    }

    /**
     * Build the context for a render about to run through $renderer.
     *
     * The effective mode is the renderer's own only where the target is HTML.
     * Static rendering is an HTML-only concern (§2.5): the Markdown, plain-text
     * and ANSI renderers reach the same end by flattening and never consult the
     * mode, so reporting a configured `static` to a hook on those targets would
     * invite it to degrade output that is not degraded, and one converter reused
     * across formats would stop producing the same non-HTML bytes.
     *
     * @param \MarkupCarve\Carve\Renderer\RendererInterface $renderer The renderer this render will run through.
     *
     * @return self
     */
    public static function forRenderer(RendererInterface $renderer): self
    {
        $targetIsHtml = $renderer instanceof HtmlRenderer;

        return new self(
            $targetIsHtml ? $renderer->getSymbols() : [],
            method_exists($renderer, 'getSmartTypography')
                ? $renderer->getSmartTypography()
                : SmartTypographyMode::Glyph,
            $targetIsHtml ? $renderer->getSafeMode() : null,
            $targetIsHtml ? $renderer->getStaticRenderers() : [],
            $targetIsHtml ? $renderer->getRenderMode() : RenderMode::INTERACTIVE,
            $targetIsHtml,
        );
    }

    /**
     * Trusted HTML replacements for `:name:` symbols, as configured.
     *
     * @return array<string, string>
     */
    public function symbols(): array
    {
        return $this->symbols;
    }

    /**
     * Whether smart typography resolves to glyphs or keeps the author's runs.
     *
     * @return \MarkupCarve\Carve\Renderer\SmartTypographyMode
     */
    public function smartTypography(): SmartTypographyMode
    {
        return $this->smartTypography;
    }

    /**
     * The configured safe mode, or null when the caller set none.
     *
     * @return \MarkupCarve\Carve\SafeMode|null
     */
    public function safeMode(): ?SafeMode
    {
        return $this->safeMode;
    }

    /**
     * The build-time renderer for a css class, or null when none is supplied.
     *
     * @param string $cssClass The fence's css class (`mermaid`, `chart`, a custom word) or `math`.
     *
     * @return \Closure(string): string|null
     */
    public function staticRenderer(string $cssClass): ?Closure
    {
        return $this->staticRenderers[$cssClass] ?? null;
    }

    /**
     * The EFFECTIVE render mode for the target format.
     *
     * Always {@see RenderMode::INTERACTIVE} on a non-HTML target whatever the
     * caller configured, because static rendering is an HTML-only concern.
     *
     * @return string A {@see RenderMode} constant.
     */
    public function mode(): string
    {
        return $this->effectiveMode;
    }

    /**
     * Whether the effective mode is {@see RenderMode::STATIC} (the static HTML path).
     *
     * @return bool
     */
    public function isStatic(): bool
    {
        return $this->effectiveMode === RenderMode::STATIC;
    }

    /**
     * Whether the final render target is HTML.
     *
     * An extension that emits HTML in `beforeRender()` reads this to SKIP its
     * transform on the Markdown, plain-text and ANSI targets and leave the source
     * node for that renderer to emit as source. This is the accessor a bare
     * options parameter had no answer for, and the reason the contract carries a
     * context at all.
     *
     * @return bool
     */
    public function targetIsHtml(): bool
    {
        return $this->targetIsHtml;
    }
}
