<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\BeforeRenderContext;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use MarkupCarve\Carve\Renderer\RenderMode;
use MarkupCarve\Carve\Renderer\SmartTypographyMode;
use MarkupCarve\Carve\Test\Fixture\CloningNavExtension;
use MarkupCarve\Carve\Test\Fixture\HtmlOnlyFenceExtension;
use MarkupCarve\Carve\Test\Fixture\PeekExtension;
use MarkupCarve\Carve\Test\Fixture\TamperExtension;
use PHPUnit\Framework\TestCase;

/**
 * A `beforeRender` hook runs before the render starts, so it has nothing to
 * inherit from: with the document alone in hand a hook that produces output of
 * its own produces it with DEFAULTS. The entry a hook clones from a heading then
 * disagrees with that heading as soon as a render option reaches inline
 * rendering - the same nodes, two answers (carve#1007).
 */
class BeforeRenderContextTest extends TestCase
{
    /**
     * THE PROVING ROW. A hook clones the heading's inline nodes into an entry of
     * its own and renders them with the map the CALLER configured, which it can
     * only know from the context. Entry and heading agree.
     *
     * The symbol sits mid-heading rather than at its start because carve-php does
     * not map a leading `:name:` in a heading at all (a divergence from carve-js,
     * unrelated to this hook and not fixed here); at the start the row would pass
     * for the wrong reason.
     */
    public function testAnEntryClonedFromAHeadingRendersWithTheCallersSymbols(): void
    {
        $converter = new CarveConverter(symbols: ['ok' => 'OK']);
        $converter->addExtension(new CloningNavExtension());

        $html = $converter->convert("{#h}\n# x :ok: y\n");

        $this->assertStringContainsString('<h1>x OK y</h1>', $html);
        $this->assertStringContainsString('<nav><a href="#h">x OK y</a></nav>', $html);
    }

    /**
     * THE CONTROL, and it is named as one: the same document with DEFAULT
     * options. It passes today and no mutation of this defect moves it. Without
     * it, "entry equals heading" is satisfied by rendering both with defaults,
     * which is exactly the bug.
     */
    public function testControlTheSameDocumentWithDefaultOptions(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new CloningNavExtension());

        $html = $converter->convert("{#h}\n# x :ok: y\n");

        $this->assertStringContainsString('<h1>x :ok: y</h1>', $html);
        $this->assertStringContainsString('<nav><a href="#h">x :ok: y</a></nav>', $html);
    }

    /**
     * `targetIsHtml` is the accessor a bare options parameter had no answer for,
     * and the reason the contract carries a context. The hook below has the shape
     * a client-script extension has: it replaces its fence with markup only the
     * HTML target can use, and on every other target it must leave the fence
     * alone so that renderer emits the source the author wrote.
     */
    public function testAHookEmittingHtmlLeavesTheSourceNodeForANonHtmlTarget(): void
    {
        $source = "```myuml\nA -> B\n```\n";

        $html = (new CarveConverter())->addExtension(new HtmlOnlyFenceExtension())->convert($source);
        $this->assertStringContainsString('<div class="myuml">DIAGRAM</div>', $html);
        $this->assertStringNotContainsString('A -&gt; B', $html);

        $markdown = (new CarveConverter(renderer: new MarkdownRenderer()))
            ->addExtension(new HtmlOnlyFenceExtension())
            ->convert($source);
        $this->assertStringContainsString('A -> B', $markdown);
        $this->assertStringNotContainsString('<div class="myuml">', $markdown);

        $plain = (new CarveConverter(renderer: new PlainTextRenderer()))
            ->addExtension(new HtmlOnlyFenceExtension())
            ->convert($source);
        $this->assertStringContainsString('A -> B', $plain);
        $this->assertStringNotContainsString('<div class="myuml">', $plain);
    }

    /**
     * The effective mode is the configured one only on the HTML target. Static
     * rendering is an HTML-only concern: the Markdown, plain-text and ANSI
     * renderers reach the same end by flattening and never consult the mode, so
     * reporting a configured `static` to a hook there would invite it to degrade
     * output that is not degraded.
     */
    public function testTheEffectiveModeIsInteractiveOnEveryNonHtmlTarget(): void
    {
        $peek = $this->peek(new CarveConverter(mode: RenderMode::STATIC));
        $this->assertSame(RenderMode::STATIC, $peek->mode);
        $this->assertTrue($peek->isStatic);
        $this->assertTrue($peek->targetIsHtml);

        $peek = $this->peek(new CarveConverter(mode: RenderMode::STATIC, renderer: new MarkdownRenderer()));
        $this->assertSame(RenderMode::INTERACTIVE, $peek->mode);
        $this->assertFalse($peek->isStatic);
        $this->assertFalse($peek->targetIsHtml);

        $peek = $this->peek(new CarveConverter(mode: RenderMode::STATIC, renderer: new PlainTextRenderer()));
        $this->assertSame(RenderMode::INTERACTIVE, $peek->mode);
        $this->assertFalse($peek->targetIsHtml);

        // CONTROL: with no mode configured the HTML target reports the default,
        // so the rows above are the caller's value arriving rather than a
        // constant this class always reports.
        $peek = $this->peek(new CarveConverter());
        $this->assertSame(RenderMode::INTERACTIVE, $peek->mode);
        $this->assertFalse($peek->isStatic);
        $this->assertTrue($peek->targetIsHtml);
    }

    /**
     * Run one render through a `PeekExtension` and return the instance the
     * converter actually called.
     *
     * `addExtension()` CLONES a before-render extension so two converters cannot
     * share its per-render state, so the object the test constructed is never the
     * object the hook ran on.
     *
     * @param \MarkupCarve\Carve\CarveConverter $converter The converter to run.
     * @param string $source The document to convert.
     *
     * @return \MarkupCarve\Carve\Test\Fixture\PeekExtension
     */
    protected function peek(CarveConverter $converter, string $source = "hi\n"): PeekExtension
    {
        $converter->addExtension(new PeekExtension());
        $converter->convert($source);
        $registered = $converter->getExtensions()[0];
        $this->assertInstanceOf(PeekExtension::class, $registered);

        return $registered;
    }

    /**
     * The context hands out the configured values, and smart typography is the
     * one that is NOT HTML-only: it reaches every renderer, so every renderer
     * answers for it.
     */
    public function testTheContextCarriesTheConfiguredOptions(): void
    {
        $peek = $this->peek(new CarveConverter(smartTypography: false, symbols: ['ok' => 'OK']));
        $this->assertSame(['ok' => 'OK'], $peek->symbols);
        $this->assertSame(SmartTypographyMode::Source, $peek->smartTypography);

        // Smart typography is the option that is NOT HTML-only, so the non-HTML
        // target answers for it too rather than reporting the default.
        $peek = $this->peek(new CarveConverter(smartTypography: false, renderer: new MarkdownRenderer()));
        $this->assertSame(SmartTypographyMode::Source, $peek->smartTypography);

        // CONTROL: unconfigured, both report the defaults.
        $peek = $this->peek(new CarveConverter());
        $this->assertSame([], $peek->symbols);
        $this->assertSame(SmartTypographyMode::Glyph, $peek->smartTypography);
    }

    /**
     * READ-ONLY is contract, not convention: the guards run after the hooks, so a
     * hook that could rewrite the options could clear the field a guard measures.
     * The context hands out VALUES - the map a hook receives is its own copy, and
     * writing to it leaves the renderer's map alone.
     */
    public function testTheMapAHookReceivesIsItsOwnCopy(): void
    {
        $converter = new CarveConverter(symbols: ['ok' => 'OK']);
        $converter->addExtension(new TamperExtension());

        $html = $converter->convert("x :ok: y\n");

        $tamper = $converter->getExtensions()[0];
        $this->assertInstanceOf(TamperExtension::class, $tamper);
        $this->assertSame(['ok' => 'OK'], $tamper->seen);
        // The renderer's own map is untouched, so the symbol still maps.
        $this->assertStringContainsString('x OK y', $html);
    }

    /**
     * The context is constructible on its own, so an extension can be unit-tested
     * without a converter.
     */
    public function testForRendererReportsANonHtmlRenderer(): void
    {
        $context = BeforeRenderContext::forRenderer(new MarkdownRenderer());

        $this->assertFalse($context->targetIsHtml());
        $this->assertSame(RenderMode::INTERACTIVE, $context->mode());
        $this->assertFalse($context->isStatic());
        $this->assertSame([], $context->symbols());
        $this->assertNull($context->safeMode());
        $this->assertNull($context->staticRenderer('mermaid'));

        $renderer = new HtmlRenderer(false, ['ok' => 'OK']);
        $renderer->setRenderMode(RenderMode::STATIC);
        $renderer->setStaticRenderers(['mermaid' => fn (string $src): string => '<svg/>']);
        $context = BeforeRenderContext::forRenderer($renderer);

        $this->assertTrue($context->targetIsHtml());
        $this->assertTrue($context->isStatic());
        $this->assertSame(['ok' => 'OK'], $context->symbols());
        $this->assertNotNull($context->staticRenderer('mermaid'));
        $this->assertNull($context->staticRenderer('chart'));
    }
}
