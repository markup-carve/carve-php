<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use Carve\Extension\CodeGroupExtension;
use Carve\Extension\FencedRenderExtension;
use Carve\Extension\MathBlockExtension;
use Carve\Extension\TabsExtension;
use Carve\Renderer\RenderMode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Static render mode + per-extension renderStatic resolution.
 *
 * Mirrors `docs/extensions.md` §2.5 and `docs/graceful-degradation.md`:
 * `mode: "static"` flattens interactive constructs (tabs / code-group →
 * labeled sections), expands client-script blocks (math / mermaid → image or
 * source), and rejects unknown mode values. The Markdown / plain-text / ANSI
 * renderers are inherently static and carry the label-caption floor regardless.
 */
class StaticRenderModeTest extends TestCase
{
    protected function tabsSource(): string
    {
        return implode("\n", [
            ':::: tabs',
            '::: tab [Installation]',
            '`composer require`',
            ':::',
            '::: tab [Usage]',
            '`convert()`',
            ':::',
            '::::',
        ]) . "\n";
    }

    public function testInteractiveModeIsTheDefault(): void
    {
        $converter = new CarveConverter();

        $this->assertSame(RenderMode::INTERACTIVE, $converter->getRenderMode());
    }

    public function testUnknownModeIsRejectedOnConstruct(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown render mode "print"');

        new CarveConverter(mode: 'print');
    }

    public function testUnknownModeIsRejectedOnSetter(): void
    {
        $converter = new CarveConverter();

        $this->expectException(InvalidArgumentException::class);
        $converter->setRenderMode('email');
    }

    public function testTabsRenderInteractiveByDefault(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new TabsExtension());

        $html = $converter->convert($this->tabsSource());

        $this->assertStringContainsString('<input type="radio"', $html);
        $this->assertStringContainsString('class="tabs-label">Installation</label>', $html);
        $this->assertStringNotContainsString('<section', $html);
    }

    public function testTabsFlattenToLabeledSectionsInStaticMode(): void
    {
        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension(new TabsExtension());

        $html = trim($converter->convert($this->tabsSource()));

        $expected = implode("\n", [
            '<div class="tabs">',
            '<section class="tabs-panel">',
            '<p class="tabs-label">Installation</p>',
            '<p><code>composer require</code></p>',
            '</section>',
            '<section class="tabs-panel">',
            '<p class="tabs-label">Usage</p>',
            '<p><code>convert()</code></p>',
            '</section>',
            '</div>',
        ]);
        $this->assertSame($expected, $html);
        // No interaction in static mode: no radio inputs.
        $this->assertStringNotContainsString('<input', $html);
    }

    public function testCodeGroupFlattensToLabeledSectionsInStaticMode(): void
    {
        $source = implode("\n", [
            '::: code-group',
            '```php [Install]',
            'composer require x',
            '```',
            '```bash [NPM]',
            'npm i x',
            '```',
            ':::',
        ]) . "\n";

        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension(new CodeGroupExtension());

        $html = trim($converter->convert($source));

        $expected = implode("\n", [
            '<div class="code-group">',
            '<section class="code-group-panel">',
            '<p class="code-group-label">Install</p>',
            '<pre><code class="language-php">composer require x',
            '</code></pre>',
            '</section>',
            '<section class="code-group-panel">',
            '<p class="code-group-label">NPM</p>',
            '<pre><code class="language-bash">npm i x',
            '</code></pre>',
            '</section>',
            '</div>',
        ]);
        $this->assertSame($expected, $html);
        $this->assertStringNotContainsString('<input', $html);
    }

    public function testMathStaticFallsBackToSourceWithoutRenderer(): void
    {
        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension(new MathBlockExtension());

        $html = trim($converter->convert("```math\n\\int_0^1 x^2 < 1\n```\n"));

        // Source preserved, escaped, never blank; no interactive \[ ... \] div.
        $this->assertSame('<pre class="math display">\int_0^1 x^2 &lt; 1</pre>', $html);
    }

    public function testMathStaticUsesSuppliedRenderer(): void
    {
        $converter = new CarveConverter(
            mode: RenderMode::STATIC,
            renderers: ['math' => fn (string $src): string => '<math>SSR:' . trim($src) . '</math>'],
        );
        $converter->addExtension(new MathBlockExtension());

        $html = trim($converter->convert("```math\n\\int_0^1 x^2\n```\n"));

        $this->assertSame('<div class="math display"><math>SSR:\int_0^1 x^2</math></div>', $html);
    }

    public function testMathInteractiveModeStillEmitsDisplayMathDiv(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new MathBlockExtension());

        $html = trim($converter->convert("```math\n\\int_0^1 x^2\n```\n"));

        $this->assertSame('<div class="math display">\[\int_0^1 x^2\]</div>', $html);
    }

    public function testMermaidStaticFallsBackToSourceWithoutRenderer(): void
    {
        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension(FencedRenderExtension::mermaid());

        $html = trim($converter->convert("```mermaid\ngraph TD; A-->B\n```\n"));

        // `>` survives as escaped text; source kept readable, never blank.
        $this->assertSame(
            '<pre class="mermaid"><code class="language-mermaid">graph TD; A--&gt;B</code></pre>',
            $html,
        );
    }

    public function testMermaidStaticUsesSuppliedRenderer(): void
    {
        $converter = new CarveConverter(
            mode: RenderMode::STATIC,
            renderers: ['mermaid' => fn (string $src): string => '<svg data-src="' . trim($src) . '"></svg>'],
        );
        $converter->addExtension(FencedRenderExtension::mermaid());

        $html = trim($converter->convert("```mermaid\ngraph TD\n```\n"));

        $this->assertSame('<div class="mermaid"><svg data-src="graph TD"></svg></div>', $html);
    }

    public function testMermaidStaticPreservesAuthorAttributes(): void
    {
        // Author attributes (id / extra classes / data-*) must survive static
        // output exactly as the interactive path keeps them.
        $source = "{#diagram .wide data-x=1}\n```mermaid\ngraph TD\n```\n";

        $noRenderer = trim((function () use ($source): string {
            $c = new CarveConverter(mode: RenderMode::STATIC);
            $c->addExtension(FencedRenderExtension::mermaid());

            return $c->convert($source);
        })());
        $this->assertSame(
            '<pre class="mermaid wide" id="diagram" data-x="1">'
            . '<code class="language-mermaid">graph TD</code></pre>',
            $noRenderer,
        );

        $withRenderer = trim((function () use ($source): string {
            $c = new CarveConverter(
                mode: RenderMode::STATIC,
                renderers: ['mermaid' => fn (string $s): string => '<svg/>'],
            );
            $c->addExtension(FencedRenderExtension::mermaid());

            return $c->convert($source);
        })());
        $this->assertSame(
            '<div class="mermaid wide" id="diagram" data-x="1"><svg/></div>',
            $withRenderer,
        );
    }

    public function testMermaidStaticPreservesRoundTripSource(): void
    {
        // Round-trip mode + static mode: the data-djot-src the interactive path
        // emits must survive so Djot -> static HTML -> Djot still reconstructs.
        $converter = new CarveConverter(roundTripMode: true, mode: RenderMode::STATIC);
        $converter->addExtension(FencedRenderExtension::mermaid());

        $html = $converter->convert("```mermaid\ngraph TD\n```\n");

        $this->assertStringContainsString('data-djot-src="``` mermaid', $html);
    }

    public function testMathStaticPreservesAuthorAttributes(): void
    {
        $source = "{#eq .big}\n```math\n\\pi\n```\n";

        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension(new MathBlockExtension());

        $html = trim($converter->convert($source));

        $this->assertSame('<pre class="math display big" id="eq">\pi</pre>', $html);
    }

    public function testMermaidInteractiveModeEmitsHydrationElement(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(FencedRenderExtension::mermaid());

        $html = trim($converter->convert("```mermaid\ngraph TD; A-->B\n```\n"));

        // Interactive text mode preserves `>` so arrow syntax survives for the
        // client library (only `&` and `<` are escaped).
        $this->assertSame('<pre class="mermaid">graph TD; A-->B</pre>', $html);
    }

    public function testUnconsumedLabelFloorAppliesInStaticModeWithoutGroupExtension(): void
    {
        // No tabs/code-group extension active: the grouping [label] would be
        // dropped, so the core caption floor surfaces it (resolution step 3).
        $converter = new CarveConverter(mode: RenderMode::STATIC);

        $html = trim($converter->convert(":::[First]\nFirst panel.\n:::\n"));

        $expected = implode("\n", [
            '<div>',
            '  <p class="div-label">First</p>',
            '  <p>First panel.</p>',
            '</div>',
        ]);
        $this->assertSame($expected, $html);
    }

    public function testMarkdownRendererForcesLabelFloorRegardlessOfMode(): void
    {
        // Markdown is inherently static: the label-caption floor applies
        // unconditionally (no mode option threads into it).
        $converter = CarveConverter::markdown();

        $markdown = trim($converter->convert(":::[Installation]\nBody.\n:::\n"));

        $this->assertStringContainsString('**Installation**', $markdown);
    }
}
