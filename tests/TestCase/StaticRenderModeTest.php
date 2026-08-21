<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use InvalidArgumentException;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CodeGroupExtension;
use MarkupCarve\Carve\Extension\DetailsExtension;
use MarkupCarve\Carve\Extension\FencedRenderExtension;
use MarkupCarve\Carve\Extension\MathBlockExtension;
use MarkupCarve\Carve\Extension\SpoilerExtension;
use MarkupCarve\Carve\Extension\TabsExtension;
use MarkupCarve\Carve\Renderer\RenderMode;
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
            '<div class="tabs" role="group" aria-label="Tabs">',
            '  <section class="tabs-panel">',
            '  <h3 class="tabs-label">Installation</h3>',
            '<p><code>composer require</code></p>',
            '  </section>',
            '  <section class="tabs-panel">',
            '  <h3 class="tabs-label">Usage</h3>',
            '<p><code>convert()</code></p>',
            '  </section>',
            '</div>',
        ]);
        $this->assertSame($expected, $html);
        // No interaction in static mode: no radio inputs.
        $this->assertStringNotContainsString('<input', $html);
    }

    public function testTabsStaticShapeMatchesCarveJsOracleWithPanelTitle(): void
    {
        $source = implode("\n", [
            ':::: tabs',
            '::: tab "T" [Name]',
            'Body.',
            ':::',
            '::: tab [Two]',
            'B2.',
            ':::',
            '::::',
        ]) . "\n";

        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension(new TabsExtension());

        $expected = implode("\n", [
            '<div class="tabs" role="group" aria-label="Tabs">',
            '  <section class="tabs-panel">',
            '  <h3 class="tabs-label">Name</h3>',
            '<p class="admonition-title">T</p>',
            '<p>Body.</p>',
            '  </section>',
            '  <section class="tabs-panel">',
            '  <h3 class="tabs-label">Two</h3>',
            '<p>B2.</p>',
            '  </section>',
            '</div>',
        ]);
        $this->assertSame($expected, trim($converter->convert($source)));
    }

    public function testTabsStaticShapeMatchesCarveJsOracleWithWrapperId(): void
    {
        $source = implode("\n", [
            '{#install}',
            ':::: tabs',
            '::: tab [One]',
            'A.',
            ':::',
            '{selected}',
            '::: tab [Two]',
            'B.',
            ':::',
            '::::',
        ]) . "\n";

        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension(new TabsExtension());

        $expected = implode("\n", [
            '<div id="install" class="tabs" role="group" aria-label="Tabs">',
            '  <section class="tabs-panel">',
            '  <h3 class="tabs-label">One</h3>',
            '<p>A.</p>',
            '  </section>',
            '  <section class="tabs-panel">',
            '  <h3 class="tabs-label">Two</h3>',
            '<p>B.</p>',
            '  </section>',
            '</div>',
        ]);
        $this->assertSame($expected, trim($converter->convert($source)));
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
            '<div class="code-group" role="group" aria-label="Code examples">',
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
            '<pre id="diagram" class="mermaid wide" data-x="1">'
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
            '<div id="diagram" class="mermaid wide" data-x="1"><svg/></div>',
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

    public function testGraphvizStaticFallsBackToSourceWithoutRenderer(): void
    {
        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension(FencedRenderExtension::graphviz());

        $html = trim($converter->convert("```graphviz\ndigraph { A -> B }\n```\n"));

        $this->assertSame(
            '<pre class="graphviz"><code class="language-graphviz">digraph { A -&gt; B }</code></pre>',
            $html,
        );
    }

    public function testGraphvizStaticUsesSuppliedRenderer(): void
    {
        $converter = new CarveConverter(
            mode: RenderMode::STATIC,
            renderers: ['graphviz' => fn (string $src): string => '<svg data-src="' . trim($src) . '"></svg>'],
        );
        $converter->addExtension(FencedRenderExtension::graphviz());

        $html = trim($converter->convert("```graphviz\ndigraph { A -> B }\n```\n"));

        $this->assertSame('<div class="graphviz"><svg data-src="digraph { A -> B }"></svg></div>', $html);
    }

    public function testGraphvizDotAliasConsultsTheGraphvizRendererKey(): void
    {
        // The graphviz preset also claims the `dot` fence word; both map to the
        // same `graphviz` renderer key (keyed by cssClass).
        $converter = new CarveConverter(
            mode: RenderMode::STATIC,
            renderers: ['graphviz' => fn (string $src): string => '<svg/>'],
        );
        $converter->addExtension(FencedRenderExtension::graphviz());

        $html = trim($converter->convert("```dot\ndigraph { A -> B }\n```\n"));

        $this->assertSame('<div class="graphviz"><svg/></div>', $html);
    }

    public function testPlantumlStaticFallsBackToSourceWithoutRenderer(): void
    {
        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension(FencedRenderExtension::plantuml());

        $html = trim($converter->convert("```plantuml\n@startuml\nA -> B\n@enduml\n```\n"));

        $this->assertSame(
            '<pre class="plantuml"><code class="language-plantuml">@startuml' . "\n"
                . 'A -&gt; B' . "\n" . '@enduml</code></pre>',
            $html,
        );
    }

    public function testPlantumlStaticUsesSuppliedRenderer(): void
    {
        $converter = new CarveConverter(
            mode: RenderMode::STATIC,
            renderers: ['plantuml' => fn (string $src): string => '<img alt="plantuml" src="uml.svg">'],
        );
        $converter->addExtension(FencedRenderExtension::plantuml());

        $html = trim($converter->convert("```plantuml\n@startuml\nA -> B\n@enduml\n```\n"));

        $this->assertSame('<div class="plantuml"><img alt="plantuml" src="uml.svg"></div>', $html);
    }

    public function testPlantumlPumlAliasConsultsThePlantumlRendererKey(): void
    {
        // The plantuml preset also claims the `puml` fence word; both map to the
        // same `plantuml` renderer key (keyed by cssClass).
        $converter = new CarveConverter(
            mode: RenderMode::STATIC,
            renderers: ['plantuml' => fn (string $src): string => '<img alt="plantuml" src="uml.svg">'],
        );
        $converter->addExtension(FencedRenderExtension::plantuml());

        $html = trim($converter->convert("```puml\nA -> B\n```\n"));

        $this->assertSame('<div class="plantuml"><img alt="plantuml" src="uml.svg"></div>', $html);
    }

    public function testCustomFenceWordIsStaticCapableViaTheOpenRenderersMap(): void
    {
        // The renderers map is open, keyed by cssClass: a custom fence word
        // renders statically with no canonical key and no engine change. This is
        // the portability the open map guarantees across engines.
        $converter = new CarveConverter(
            mode: RenderMode::STATIC,
            renderers: ['myuml' => fn (string $src): string => '<img alt="myuml" src="my.svg">'],
        );
        $converter->addExtension(new FencedRenderExtension(language: 'myuml', cssClass: 'myuml'));

        $html = trim($converter->convert("```myuml\nA -> B\n```\n"));

        $this->assertSame('<div class="myuml"><img alt="myuml" src="my.svg"></div>', $html);
    }

    public function testCustomFenceWithoutRendererDegradesToSource(): void
    {
        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension(new FencedRenderExtension(language: 'myuml', cssClass: 'myuml'));

        $html = trim($converter->convert("```myuml\nA -> B\n```\n"));

        $this->assertSame(
            '<pre class="myuml"><code class="language-myuml">A -&gt; B</code></pre>',
            $html,
        );
    }

    public function testMathStaticPreservesAuthorAttributes(): void
    {
        $source = "{#eq .big}\n```math\n\\pi\n```\n";

        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension(new MathBlockExtension());

        $html = trim($converter->convert($source));

        $this->assertSame('<pre id="eq" class="math display big">\pi</pre>', $html);
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

    protected function detailsSource(): string
    {
        return implode("\n", [
            ':::: details "More info"',
            'Hidden until the reader expands it.',
            '::::',
        ]) . "\n";
    }

    public function testDetailsStaysNativeWithoutOpenInInteractiveMode(): void
    {
        // A disclosure is a native `<details>` element in all modes; the
        // interactive consumer can click to expand, so no forced `open`.
        $converter = new CarveConverter();
        $converter->addExtension(new DetailsExtension());

        $html = trim($converter->convert($this->detailsSource()));

        $expected = implode("\n", [
            '<details>',
            '  <summary>More info</summary>',
            '  <p>Hidden until the reader expands it.</p>',
            '</details>',
        ]);
        $this->assertSame($expected, $html);
        $this->assertStringNotContainsString('open', $html);
    }

    public function testDetailsCarriesOpenInStaticMode(): void
    {
        // Static mode targets a non-interactive consumer (print / PDF engine)
        // that never clicks to expand, so the disclosure MUST carry `open` to
        // keep the body visible. See docs/graceful-degradation.md.
        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension(new DetailsExtension());

        $html = trim($converter->convert($this->detailsSource()));

        $expected = implode("\n", [
            '<details open>',
            '  <summary>More info</summary>',
            '  <p>Hidden until the reader expands it.</p>',
            '</details>',
        ]);
        $this->assertSame($expected, $html);
    }

    public function testDetailsDoesNotDuplicateAuthorOpenInStaticMode(): void
    {
        // An author-supplied `open` attribute already renders via the normal
        // attribute path, so static mode must not append a second `open`.
        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension(new DetailsExtension());

        $html = trim($converter->convert("{#faq open}\n" . $this->detailsSource()));

        $this->assertSame(1, substr_count($html, 'open'));
        $this->assertStringContainsString('<details id="faq" open="">', $html);
    }

    public function testDetailsDoesNotDuplicateCaseVariantAuthorOpenInStaticMode(): void
    {
        // HTML attribute names are case-insensitive; a `{Open}` variant the
        // parser preserves verbatim must still suppress the forced fallback so
        // the tag never carries a duplicate equivalent `open` attribute.
        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension(new DetailsExtension());

        $html = trim($converter->convert("{Open}\n" . $this->detailsSource()));

        $this->assertSame(1, substr_count(strtolower($html), 'open'));
        $this->assertStringContainsString('<details Open="">', $html);
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

    public function testSpoilerStaysACollapsedDisclosureInInteractiveMode(): void
    {
        // The interactive form is the disclosure the reader clicks. It is the
        // baseline the static assertions below have to differ from - without
        // it, a static path that changed nothing would look like agreement.
        $converter = new CarveConverter();
        $converter->addExtension(new SpoilerExtension());

        $html = trim($converter->convert("::: spoiler\nhidden text\n:::\n"));

        $expected = implode("\n", [
            '<details class="spoiler">',
            '  <summary>Spoiler</summary>',
            '  <p>hidden text</p>',
            '</details>',
        ]);
        $this->assertSame($expected, $html);
    }

    public function testSpoilerBlockIsRevealedInStaticMode(): void
    {
        // docs/graceful-degradation.md, spoiler row: "blurred until revealed |
        // revealed | degrades natively (hiding is meaningless offline)". A
        // <details> with no `open` renders COLLAPSED in a print engine, so
        // leaving the interactive form in place loses the body on the way to
        // PDF - the one thing the page's principle forbids.
        //
        // Byte-for-byte the carve-js and carve-rs oracle output.
        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension(new SpoilerExtension());

        $html = trim($converter->convert("::: spoiler\nhidden text\n:::\n"));

        $expected = implode("\n", [
            '<section class="spoiler spoiler-revealed">',
            '  <h3 class="spoiler-title">Spoiler</h3>',
            '  <p>hidden text</p>',
            '</section>',
        ]);
        $this->assertSame($expected, $html);
        // Asserted on the VALUE, not on a shape the defect also produced: the
        // unfixed engine emitted `<details class="spoiler">` here, which still
        // contains the body text and would satisfy a content-only assertion.
        $this->assertStringNotContainsString('<details', $html);
    }

    public function testSpoilerBlockTitleBecomesTheRevealedHeading(): void
    {
        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension(new SpoilerExtension());

        $html = trim($converter->convert("::: spoiler \"Ending\"\nEveryone lives.\n:::\n"));

        $expected = implode("\n", [
            '<section class="spoiler spoiler-revealed">',
            '  <h3 class="spoiler-title">Ending</h3>',
            '  <p>Everyone lives.</p>',
            '</section>',
        ]);
        $this->assertSame($expected, $html);
        $this->assertStringNotContainsString('<summary>', $html);
    }

    public function testSpoilerBlockKeepsItsGroupingLabelAsACaption(): void
    {
        // The static path CONSUMES the node, so the core caption floor never
        // runs on it. Without the label line here the `[label]` would be
        // dropped exactly where the page says a label must not be - and the
        // rest of the output would still look right.
        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension(new SpoilerExtension());

        $html = trim($converter->convert("::: spoiler \"T\" [Lbl]\nbody\n:::\n"));

        $expected = implode("\n", [
            '<section class="spoiler spoiler-revealed">',
            '  <h3 class="spoiler-title">T</h3>',
            '  <p class="div-label">Lbl</p>',
            '  <p>body</p>',
            '</section>',
        ]);
        $this->assertSame($expected, $html);
    }

    public function testSpoilerBlockAuthorAttributesSurviveTheStaticPath(): void
    {
        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension(new SpoilerExtension());

        $html = trim($converter->convert("{#s .extra}\n::: spoiler\nbody\n:::\n"));

        $this->assertStringContainsString('<section id="s" class="spoiler spoiler-revealed extra">', $html);
    }

    public function testSpoilerBlockInsideAContainerIsRevealedToo(): void
    {
        // Nesting reaches the static hook through renderChildren(), so a fix
        // wired only to the top level would pass every case above and fail
        // here.
        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension(new SpoilerExtension());

        $html = trim($converter->convert("> ::: spoiler\n> b\n> :::\n"));

        $expected = implode("\n", [
            '<blockquote>',
            '  <section class="spoiler spoiler-revealed">',
            '    <h3 class="spoiler-title">Spoiler</h3>',
            '    <p>b</p>',
            '  </section>',
            '</blockquote>',
        ]);
        $this->assertSame($expected, $html);
    }

    public function testSpoilerInlineIsRevealedInStaticMode(): void
    {
        // The second producer of the same omission. `class="spoiler"` alone IS
        // the blur trigger the host stylesheet keys off, so an inline spoiler
        // that reaches print unmarked is invisible there - the content is in
        // the HTML and not on the page.
        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension(new SpoilerExtension());

        $html = trim($converter->convert("a :spoiler[hi *there*] b\n"));

        $this->assertSame(
            '<p>a <span class="spoiler spoiler-revealed">hi <strong>there</strong></span> b</p>',
            $html,
        );
    }

    public function testSpoilerInlineStaysBlurredInInteractiveMode(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new SpoilerExtension());

        $html = trim($converter->convert("a :spoiler[hi] b\n"));

        $this->assertSame('<p>a <span class="spoiler">hi</span> b</p>', $html);
    }

    public function testSpoilerInlineAuthorAttributesKeepTheirSourceOrder(): void
    {
        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension(new SpoilerExtension());

        $html = trim($converter->convert("a :spoiler[hi]{#x .y} b\n"));

        $this->assertSame('<p>a <span id="x" class="spoiler spoiler-revealed y">hi</span> b</p>', $html);
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
