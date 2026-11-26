<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Extension;

use InvalidArgumentException;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\Bundle;
use MarkupCarve\Carve\Extension\CodeCallouts;
use MarkupCarve\Carve\Extension\CodeGroup;
use MarkupCarve\Carve\Extension\FencedRender;
use MarkupCarve\Carve\Extension\MathBlock;
use MarkupCarve\Carve\OptionError;
use PHPUnit\Framework\TestCase;

/**
 * The carve-js reference cases of markup-carve/carve-js#5068 (test/extensions-fenced-renderers.test.ts).
 */
final class FencedRenderersTest extends TestCase
{
    /**
     * @var string
     */
    private const GROUP = "::: code-group\n```js [NPM]\nnpm i\n```\n\n```sh\nyarn add\n```\n:::\n";

    /**
     * @var string
     */
    private const SAMPLE = "```js\nlet a = 1 <1>\nlet b = 2 <2>\n```\n<1> First\n<2> Second *b*\n";

    private static function html(string $source, Bundle $bundle, ?string $mode = null): string
    {
        return Carve::render($source, ['extensions' => [$bundle]] + ($mode === null ? [] : ['mode' => $mode]));
    }

    /**
     * @return array<string>
     */
    private static function codes(string $source, Bundle $bundle, ?string $mode = null): array
    {
        $result = Carve::renderWithDiagnostics($source, ['extensions' => [$bundle]] + ($mode === null ? [] : ['mode' => $mode]));

        return array_map(static fn ($d): string => $d->code, $result->diagnostics);
    }

    private static function callouts(string $source, ?string $mode = null, ?Bundle $bundle = null): string
    {
        $bundle ??= CodeCallouts::bundle();
        $options = ['extensions' => [$bundle]] + ($mode === null ? [] : ['mode' => $mode]);

        return Carve::renderWithDocumentTransforms($source, $bundle->transforms(), $options)->html;
    }

    private static function pre(string $language, string $value): string
    {
        return '<pre><code class="language-' . $language . '">' . $value . "\n</code></pre>";
    }

    public function testExpectedOfTheIssue(): void
    {
        $this->assertSame(
            '<pre id="d" class="mermaid x" role="img" aria-label="mermaid">graph TD; A-->B&lt;C</pre>',
            self::html("{#d .x}\n```mermaid\ngraph TD; A-->B<C\n```", FencedRender::mermaid()),
        );
    }

    public function testTextModeEscapesAmpersandAndLessThanButKeepsGreaterThan(): void
    {
        $this->assertSame(
            '<pre class="d2" role="img" aria-label="d2">a -> b &amp; &lt;c</pre>',
            self::html("```d2\na -> b & <c\n```", FencedRender::d2()),
        );
    }

    public function testGraphvizClaimsDotAndGraphviz(): void
    {
        foreach (['dot', 'graphviz'] as $word) {
            $this->assertSame(
                '<pre class="graphviz" role="img" aria-label="graphviz">a -> b</pre>',
                self::html("```$word\na -> b\n```", FencedRender::graphviz()),
            );
        }
    }

    public function testJsonModeWrapsTheBodyInAScriptAndGuardsTheCloser(): void
    {
        $this->assertSame(
            '<div class="vega-lite" role="img" aria-label="vega-lite"><script type="application/json">{"mark": "bar"}</script></div>',
            self::html("```vega-lite\n{\"mark\": \"bar\"}\n```", FencedRender::vegaLite()),
        );
        $guarded = self::html("```chart\n{\"x\": \"</script>\"}\n```", FencedRender::chart());
        $this->assertSame(
            '<div class="chart" role="img" aria-label="chart"><script type="application/json">{"x": "<\/script>"}</script></div>',
            $guarded,
        );
        $this->assertSame(1, substr_count($guarded, '</script>'));
    }

    public function testAnUnclaimedLanguageStaysCore(): void
    {
        $this->assertSame(Carve::render("```python\nprint(1)\n```"), self::html("```python\nprint(1)\n```", FencedRender::d2()));
    }

    public function testTheCssClassLeadsAndTheAttributesStay(): void
    {
        $this->assertSame(
            '<pre id="c1" class="d2 tall" data-theme="dark" role="img" aria-label="d2">a -> b</pre>',
            self::html("{#c1 .tall data-theme=dark}\n```d2\na -> b\n```", FencedRender::d2()),
        );
        $this->assertStringNotContainsString('onclick', self::html("{#c1 onclick=\"alert(1)\"}\n```d2\na\n```", FencedRender::d2()));
    }

    public function testAnAuthoredNameAndRoleWinAndNoRoleWithoutAName(): void
    {
        $this->assertSame(
            '<pre class="d2" aria-label="Deploy flow" role="img">a</pre>',
            self::html("{aria-label=\"Deploy flow\"}\n```d2\na\n```", FencedRender::d2()),
        );
        $this->assertSame(
            '<pre class="d2" role="figure" aria-label="d2">a</pre>',
            self::html("{role=figure}\n```d2\na\n```", FencedRender::d2()),
        );
        $this->assertSame('<pre class="d2">a</pre>', self::html("```d2\na\n```", FencedRender::bundle('d2', label: '')));
    }

    public function testFigureTagAndClassOptions(): void
    {
        $this->assertSame(
            '<figure class="d2-figure"><pre class="d2" role="img" aria-label="d2">a</pre></figure>',
            self::html("```d2\na\n```", FencedRender::bundle('d2', wrapInFigure: true)),
        );
        $this->assertSame(
            '<div class="diagram" role="img" aria-label="diagram">a</div>',
            self::html("```d2\na\n```", FencedRender::bundle('d2', cssClass: 'diagram', tag: 'div')),
        );
    }

    public function testTheTextPresets(): void
    {
        $this->assertSame('<pre class="abc" role="img" aria-label="abc">CDEF</pre>', self::html("```abc\nCDEF\n```", FencedRender::abc()));
        $this->assertSame(
            '<pre class="wavedrom" role="img" aria-label="wavedrom">{}</pre>',
            self::html("```wavedrom\n{}\n```", FencedRender::wavedrom()),
        );
        $this->assertSame(
            "<pre class=\"plantuml\" role=\"img\" aria-label=\"plantuml\">A &lt;|-- B\nC --> D</pre>",
            self::html("```puml\nA <|-- B\nC --> D\n```", FencedRender::plantuml()),
        );
        $source = "```mermaid\ngraph TD; A-->B\n```";
        $this->assertSame(self::html($source, FencedRender::bundle('mermaid')), self::html($source, FencedRender::mermaid()));
    }

    public function testAnEmptyLanguageIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        FencedRender::bundle([]);
    }

    public function testPresetsRegisterEveryFenceWord(): void
    {
        $all = FencedRender::presets();
        $words = array_map(static fn ($d): ?string => $d->matcher['name'] ?? null, $all->definitions());
        $this->assertSame(['mermaid', 'd2', 'dot', 'graphviz', 'wavedrom', 'abc', 'plantuml', 'puml', 'vega-lite', 'chart'], $words);
        $this->assertSame(
            '<pre class="graphviz" role="img" aria-label="graphviz">digraph { a -> b }</pre>',
            self::html("```dot\ndigraph { a -> b }\n```", $all),
        );
    }

    public function testASecondClaimOfOneFenceWordFails(): void
    {
        $this->expectException(OptionError::class);
        $this->expectExceptionMessageMatches('/duplicate/');
        self::html("```mermaid\na\n```", Bundle::merge(FencedRender::mermaid(), FencedRender::bundle('mermaid', cssClass: 'm')));
    }

    public function testTheTreeCarriesTheFallbackAndTheSourceAndRoundTrips(): void
    {
        $options = ['extensions' => [FencedRender::d2()]];
        $tree = Carve::parse("{#c1}\n```d2\na\n```", $options);
        $json = json_decode(Carve::encodeTree($tree), true);
        $node = $json['children'][0];
        $this->assertSame('block_extension', $node['type']);
        $this->assertSame('carve.fencedRender.d2', $node['name']);
        $this->assertSame(['id' => 'c1'], $node['attrs']);
        $this->assertSame(['language' => 'd2'], $node['props']);
        $this->assertSame(['body' => "a\n", 'form' => 'fence', 'name' => 'd2'], $node['source']);
        $this->assertSame('code_block', $node['fallback'][0]['type']);
        $this->assertArrayNotHasKey('attrs', $node['fallback'][0]);
        $this->assertSame([['name' => 'carve.fencedRender.d2', 'required' => false, 'slots' => [], 'version' => '1']], $json['extensions']);
        $this->assertSame(Carve::encodeTree($tree), Carve::encodeTree(Carve::decodeTree(Carve::encodeTree($tree), $options)));
        $source = "{#c1 .x}\n```mermaid\ngraph TD; A-->B\n```\n";
        $options = ['extensions' => [FencedRender::mermaid()]];
        $this->assertSame($source, Carve::write(Carve::parse($source, $options), null, $options));
    }

    public function testWithoutARendererTheFallbackIsWrittenOnce(): void
    {
        $only = FencedRender::d2()->withoutRenderers();
        $this->assertSame("<pre><code class=\"language-d2\">a\n</code></pre>", self::html("```d2\na\n```", $only));
        $this->assertContains('extension-fallback-rendered', self::codes("```d2\na\n```", $only));
        $this->assertSame(
            "<div id=\"c1\" class=\"x\">\n  <pre><code class=\"language-d2\">a\n</code></pre>\n</div>",
            self::html("{#c1 .x}\n```d2\na\n```", $only),
        );
    }

    public function testADroppedEventHandlerLeavesNoFallbackCarrier(): void
    {
        $this->assertSame(
            "<pre><code class=\"language-d2\">a\n</code></pre>",
            self::html("{onclick=\"x\"}\n```d2\na\n```\n", FencedRender::d2(), 'static'),
        );
    }

    public function testStaticModeUsesTheHostBuildFunctionOrTheFallback(): void
    {
        $this->assertSame(
            "<pre><code class=\"language-mermaid\">a\n</code></pre>",
            self::html("```mermaid\na\n```", FencedRender::mermaid(), 'static'),
        );
        $bundle = FencedRender::mermaid(build: static fn (string $source): string => '<svg data-n="' . strlen($source) . '"></svg>');
        $this->assertSame(
            '<div id="m" class="mermaid" role="img" aria-label="mermaid"><svg data-n="3"></svg></div>',
            self::html("{#m}\n```mermaid\nabc\n```", $bundle, 'static'),
        );
        $this->assertNotContains('extension-fallback-rendered', self::codes("```mermaid\nabc\n```", $bundle, 'static'));
        $bundle = FencedRender::presets(['chart' => static fn (): string => '<img alt="c">']);
        $this->assertSame(
            '<div class="chart" role="img" aria-label="chart"><img alt="c"></div>',
            self::html("```chart\n{}\n```", $bundle, 'static'),
        );
        $this->assertSame("<pre><code class=\"language-d2\">a\n</code></pre>", self::html("```d2\na\n```", $bundle, 'static'));
    }

    public function testMathBlock(): void
    {
        $this->assertSame('<div class="math display" role="math">\[x^2\]</div>', self::html("```math\nx^2\n```", MathBlock::bundle()));
        $this->assertSame(
            '<div class="math display" role="math">\[a&lt;b &amp; c&gt;d\]</div>',
            self::html("```math\na<b & c>d\n```", MathBlock::bundle()),
        );
        $this->assertSame('<div class="math display" role="math">\[x\]</div>', self::html("```tex\nx\n```", MathBlock::bundle('tex')));
        $this->assertSame(Carve::render("```js\nx\n```"), self::html("```js\nx\n```", MathBlock::bundle()));
        $this->assertSame("<pre><code class=\"language-math\">x\n</code></pre>", Carve::render("```math\nx\n```"));
        $this->assertSame(
            '<div id="e1" class="math display big" data-n="1" role="math">\[x\]</div>',
            self::html("{#e1 .big data-n=1}\n```math\nx\n```", MathBlock::bundle()),
        );
        $build = static fn (string $tex, bool $display): string => '<math display="' . ($display ? 'block' : 'inline') . '">'
            . $tex . '</math>';
        $this->assertSame(
            '<div class="math display" role="math"><math display="block">x</math></div>',
            self::html("```math\nx\n```", MathBlock::bundle(build: $build), 'static'),
        );
        $this->assertSame(
            "<pre><code class=\"language-math\">x\n</code></pre>",
            self::html("```math\nx\n```", MathBlock::bundle(), 'static'),
        );
    }

    public function testCodeGroupCssMode(): void
    {
        $this->assertSame(
            '<div class="code-group" role="group" aria-label="Code examples">'
            . '<input type="radio" name="codegroup-1" id="codegroup-1-tab-1" class="code-group-radio" checked>'
            . '<label for="codegroup-1-tab-1" class="code-group-label">NPM</label>'
            . '<input type="radio" name="codegroup-1" id="codegroup-1-tab-2" class="code-group-radio">'
            . '<label for="codegroup-1-tab-2" class="code-group-label">sh</label>'
            . '<div class="code-group-panel" role="group" aria-label="NPM">' . self::pre('js', 'npm i') . '</div>'
            . '<div class="code-group-panel" role="group" aria-label="sh">' . self::pre('sh', 'yarn add') . '</div></div>',
            self::html(self::GROUP, CodeGroup::bundle()),
        );
    }

    public function testCodeGroupAriaMode(): void
    {
        $this->assertSame(
            '<div class="code-group" role="tablist" aria-label="Code examples">'
            . '<button type="button" role="tab" id="codegroup-1-tab-1" aria-selected="true" aria-controls="codegroup-1-panel-1" '
            . 'class="code-group-label">NPM</button>'
            . '<button type="button" role="tab" id="codegroup-1-tab-2" aria-selected="false" aria-controls="codegroup-1-panel-2" '
            . 'class="code-group-label" tabindex="-1">sh</button>'
            . '<div role="tabpanel" id="codegroup-1-panel-1" aria-labelledby="codegroup-1-tab-1" class="code-group-panel">'
            . self::pre('js', 'npm i') . '</div>'
            . '<div role="tabpanel" id="codegroup-1-panel-2" aria-labelledby="codegroup-1-tab-2" class="code-group-panel" hidden>'
            . self::pre('sh', 'yarn add') . '</div></div>',
            self::html(self::GROUP, CodeGroup::bundle(mode: 'aria')),
        );
    }

    public function testCodeGroupRefusesAnUnknownMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/mode/');
        CodeGroup::bundle(mode: 'js');
    }

    public function testCodeGroupSelectionLabelsAndAttributes(): void
    {
        $output = self::html("::: code-group\n```js\na\n```\n\n{selected}\n```sh\nb\n```\n:::\n", CodeGroup::bundle());
        $this->assertStringContainsString('id="codegroup-1-tab-2" class="code-group-radio" checked>', $output);
        $this->assertStringNotContainsString('selected', $output);
        $output = self::html("::: code-group\n```js \"Script\"\na\n```\n\n```\nb\n```\n:::\n", CodeGroup::bundle());
        $this->assertStringContainsString('aria-label="Script"', $output);
        $this->assertStringContainsString('aria-label="Code 2"', $output);
        $this->assertStringStartsWith(
            '<div id="g" class="code-group wide" aria-label="Install" role="group">',
            self::html("{#g .wide aria-label=\"Install\"}\n" . self::GROUP, CodeGroup::bundle()),
        );
        $labeled = Carve::render(self::GROUP, ['extensions' => [CodeGroup::bundle()], 'labels' => ['codeGroup' => 'Beispiele']]);
        $this->assertStringContainsString('aria-label="Beispiele"', $labeled);
        $this->assertStringContainsString('aria-label="Shells"', self::html(self::GROUP, CodeGroup::bundle(groupLabel: 'Shells')));
    }

    public function testCodeGroupIdsFollowDocumentOrderAndAvoidAuthoredIds(): void
    {
        $output = self::html("[x]{#codegroup-1}\n\n" . self::GROUP . "\n" . self::GROUP, CodeGroup::bundle());
        $this->assertStringContainsString('name="codegroup-2"', $output);
        $this->assertStringContainsString('name="codegroup-3"', $output);
    }

    public function testCodeGroupHighlighterAndCoreDivs(): void
    {
        $highlighter = static fn (string $code, ?string $language): string => '<x-' . ($language ?? 'none') . '>' . $code
            . '</x-' . ($language ?? 'none') . '>';
        $this->assertStringContainsString('<x-js>npm i</x-js>', self::html(self::GROUP, CodeGroup::bundle(highlighter: $highlighter)));
        foreach (["::: code-group\nText.\n\n```js\na\n```\n:::\n", "::: code-group\nText.\n:::\n"] as $mixed) {
            $this->assertSame(Carve::render($mixed), self::html($mixed, CodeGroup::bundle()));
        }
    }

    public function testCodeGroupStaticModeAndFallback(): void
    {
        $this->assertSame(
            '<div class="code-group" role="group" aria-label="Code examples">'
            . '<section class="code-group-panel"><h3 class="code-group-label">NPM</h3>' . self::pre('js', 'npm i') . '</section>'
            . '<section class="code-group-panel"><h3 class="code-group-label">sh</h3>' . self::pre('sh', 'yarn add') . '</section></div>',
            self::html(self::GROUP, CodeGroup::bundle(), 'static'),
        );
        $only = CodeGroup::bundle()->withoutRenderers();
        $this->assertSame(self::pre('js', 'npm i') . "\n" . self::pre('sh', 'yarn add'), self::html(self::GROUP, $only));
        $options = ['extensions' => [$only]];
        $this->assertSame(self::GROUP, Carve::write(Carve::parse(self::GROUP, $options), null, $options));
    }

    public function testCodeCallouts(): void
    {
        $this->assertSame(
            "<pre><code class=\"language-js\">let a = 1 <b class=\"callout\" data-callout=\"1\">1</b>\n"
            . "let b = 2 <b class=\"callout\" data-callout=\"2\">2</b>\n</code></pre>\n"
            . '<ol class="callouts"><li value="1">First</li><li value="2">Second <strong>b</strong></li></ol>',
            self::callouts(self::SAMPLE),
        );
        $this->assertSame(
            "<pre><code>if a &lt; b &amp;&amp; c <b class=\"callout\" data-callout=\"7\">7</b>\n</code></pre>\n"
            . '<ol class="callouts"><li value="7">Seven</li></ol>',
            self::callouts("```\nif a < b && c <7>\n```\n<7> Seven\n"),
        );
        $this->assertSame("<pre><code>a &lt;1&gt; b\n</code></pre>", self::callouts("```\na <1> b\n```\n"));
        $this->assertSame(Carve::render("```\na\n```\n<1> First\n"), self::callouts("```\na\n```\n<1> First\n"));
        $this->assertStringContainsString("<p>&lt;1&gt; First\nplain</p>", self::callouts("```\na <1>\n```\n<1> First\nplain\n"));
        $this->assertSame(
            "<pre id=\"c\"><code>a <b class=\"callout\" data-callout=\"1\">1</b>\n</code></pre>\n"
            . '<ol id="l" class="callouts x"><li value="1">One</li></ol>',
            self::callouts("{#c}\n```\na <1>\n```\n\n{#l .x}\n<1> One\n"),
        );
        $this->assertStringContainsString(
            '<ol class="callouts"><li value="1">One</li></ol>',
            self::callouts("> ```\n> a <1>\n> ```\n> <1> One\n"),
        );
        $this->assertStringContainsString('<b class="callout" data-callout="1">1</b>', self::callouts("- ```\n  a <1>\n  ```\n"));
        $this->assertSame(self::callouts(self::SAMPLE), self::callouts(self::SAMPLE, 'static'));
        $this->assertSame(Carve::render(self::SAMPLE), self::callouts(self::SAMPLE, null, CodeCallouts::bundle()->withoutRenderers()));
    }

    public function testCodeCalloutsLeaveTheAuthoredTreeAlone(): void
    {
        $this->assertStringContainsString('<p>&lt;1&gt; First', Carve::render(self::SAMPLE));
        $tree = Carve::parse(self::SAMPLE);
        $before = Carve::encodeTree($tree);
        $bundle = CodeCallouts::bundle();
        Carve::renderWithDocumentTransforms($tree, $bundle->transforms(), ['extensions' => [$bundle]]);
        $this->assertSame($before, Carve::encodeTree($tree));
    }

    public function testTheGroupComposes(): void
    {
        $all = Bundle::merge(FencedRender::presets(), MathBlock::bundle(), CodeGroup::bundle(), CodeCallouts::bundle());
        $source = "```mermaid\nA <1>\n```\n\n" . self::GROUP . "\n```math\nx\n```\n";
        $output = Carve::renderWithDocumentTransforms($source, $all->transforms(), ['extensions' => [$all]])->html;
        $this->assertStringContainsString('<pre class="mermaid" role="img" aria-label="mermaid">A &lt;1></pre>', $output);
        $this->assertStringContainsString('<div class="code-group"', $output);
        $this->assertStringContainsString('<div class="math display" role="math">\[x\]</div>', $output);

        $all = Bundle::merge(CodeGroup::bundle(), CodeCallouts::bundle());
        $source = "::: code-group\n```js\na\n```\n\n{selected}\n```sh\nb <1>\n```\n:::\n";
        $output = Carve::renderWithDocumentTransforms($source, $all->transforms(), ['extensions' => [$all]])->html;
        $this->assertStringContainsString('id="codegroup-1-tab-2" class="code-group-radio" checked>', $output);
        $this->assertStringContainsString(
            '<div class="code-group-panel" role="group" aria-label="sh"><pre><code class="language-sh">'
            . "b <b class=\"callout\" data-callout=\"1\">1</b>\n</code></pre></div>",
            $output,
        );
        $this->assertStringNotContainsString('selected', $output);
    }
}
