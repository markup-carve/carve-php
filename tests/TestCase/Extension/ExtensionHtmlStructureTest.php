<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Extension\CodeCalloutsExtension;
use MarkupCarve\Carve\Extension\CodeGroupExtension;
use MarkupCarve\Carve\Extension\ExtensionInterface;
use MarkupCarve\Carve\Extension\FencedRenderExtension;
use MarkupCarve\Carve\Extension\TabsExtension;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\RenderMode;
use PHPUnit\Framework\TestCase;

class ExtensionHtmlStructureTest extends TestCase
{
    private function export(string $source, ExtensionInterface $extension, bool $static = false, bool $roundtrip = true): string
    {
        $renderer = new HtmlRenderer();
        $renderer->setRoundTripMode($roundtrip);
        if ($static) {
            $renderer->setRenderMode(RenderMode::STATIC);
            $renderer->setStaticRenderers(['mermaid' => static fn (string $content): string => '<svg><text>diagram</text></svg>']);
        }
        $converter = new CarveConverter(renderer: $renderer, mode: $static ? RenderMode::STATIC : RenderMode::INTERACTIVE);
        $converter->addExtension($extension);

        $html = $converter->convert($source);
        $this->assertStringNotContainsString('data-carve-src', $html);

        return $html;
    }

    /**
     * The import must rebuild the source's tree; spelling the canonical writer chooses (fence padding, blank
     * lines between a group's blocks) is not part of the claim.
     */
    private function assertSameTree(string $expected, string $actual): void
    {
        $tree = static fn (string $carve): array => array_diff_key((new AstCodec())->encode((new BlockParser())->parse($carve)), ['srcByteLength' => true]);
        $this->assertSame($this->normalizeFirstSelection($tree($expected)), $this->normalizeFirstSelection($tree($actual)), $actual);
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private function normalizeFirstSelection(array $node): array
    {
        // First-tab selection is the default spelling, so its explicit marker is not recorded.
        if (($node['type'] ?? null) === 'admonition' && in_array($node['kind'] ?? null, ['tabs', 'code-group'], true)) {
            if (isset($node['children'][0]['attrs']['keyValues']['selected'])) {
                $attrs = &$node['children'][0]['attrs'];
                unset($attrs['keyValues']['selected']);
                $attrs['order'] = array_values(array_filter($attrs['order'] ?? [], static fn (string $key): bool => $key !== 'selected'));
                $attrs = array_filter($attrs, static fn (array|string $value): bool => $value !== []);
                if ($attrs === []) {
                    unset($node['children'][0]['attrs']);
                }
                unset($attrs);
            }
        }
        foreach ($node as &$value) {
            if (is_array($value)) {
                $value = $this->normalizeFirstSelection($value);
            }
        }

        return $node;
    }

    public function testFencePayloadEdgesAndAuthoredNamingRoundTrip(): void
    {
        foreach (['mermaid' => FencedRenderExtension::mermaid(), 'd2' => FencedRenderExtension::d2(), 'chart' => FencedRenderExtension::chart()] as $language => $extension) {
            foreach ([false, true] as $static) {
                foreach (["\ngraph TD;", "graph TD;\n", "\ngraph TD;\n", "\n"] as $payload) {
                    foreach (['', '{role="presentation"}', '{role="img" aria-label="mermaid"}', '{aria-labelledby="caption"}', '{aria-label="custom"}'] as $attrs) {
                        $source = ($attrs !== '' ? $attrs . "\n" : '') . "```$language\n$payload\n```\n";
                        $result = (new HtmlToCarve())->convertWithReport($this->export($source, clone $extension, $static));
                        $this->assertSameTree($source, $result->value);
                        $this->assertSame([], $result->report()['diagnostics']);
                    }
                }
            }
        }
    }

    public function testCustomHighlighterKeepsCodeMetadata(): void
    {
        foreach (['css', 'aria'] as $mode) {
            foreach ([false, true] as $wrapper) {
                $highlighter = static fn (string $code, ?string $language): string => ($wrapper ? '<div>' : '')
                    . '<pre><code>' . htmlspecialchars($code) . '</code></pre>' . ($wrapper ? '</div>' : '');
                $source = "::: code-group\n{#install .wide data-note=note}\n```php [Install]\na\n\n```\n:::";
                $result = (new HtmlToCarve())->convertWithReport($this->export($source, new CodeGroupExtension(mode: $mode, highlighter: $highlighter)));
                $this->assertSameTree($source, $result->value);
                $this->assertSame([], $result->report()['diagnostics']);
            }
        }
    }

    public function testCustomizedClassesRoundTripWithoutLoss(): void
    {
        foreach (['css', 'aria'] as $mode) {
            foreach ([TabsExtension::class, CodeGroupExtension::class] as $class) {
                $tabs = $class === TabsExtension::class;
                $options = ['wrapperClass', $tabs ? 'tabClass' : 'panelClass', 'labelClass', 'radioClass'];
                $source = $tabs
                    ? "{.wide}\n:::: tabs\n{.body}\n::: tab [First]\nBody.\n:::\n::::\n"
                    : "{.wide}\n::: code-group\n```php [Install]\na\n```\n:::";
                foreach (array_merge($options, ['all']) as $option) {
                    $arguments = ['mode' => $mode];
                    foreach ($option === 'all' ? $options : [$option] as $name) {
                        $arguments[$name] = 'custom-' . strtolower($name);
                    }
                    $html = $this->export($source, new $class(...$arguments));
                    $this->assertStringContainsString('data-carve-ext="' . ($tabs ? 'tabs' : 'code-group') . '"', $html);
                    foreach (['safe', 'semantic', 'roundtrip'] as $importMode) {
                        $result = (new HtmlToCarve(importMode: $importMode))->convertWithReport($html);
                        $this->assertSameTree($source, $result->value);
                        $this->assertSame([], $result->report()['diagnostics'], $html);
                    }
                }
                $this->assertStringNotContainsString('data-carve-ext=', $this->export($source, new $class(mode: $mode), roundtrip: false));
            }
        }
    }

    public function testNestedFencesUseOriginalPanelDiagnostics(): void
    {
        $source = ":::: tabs\n::: tab [Chart]\n```chart\n{}\n```\n:::\n::: tab [Diagram]\n```mermaid\ngraph TD;\n```\n:::\n::::\n";
        foreach (['css', 'aria'] as $mode) {
            $renderer = new HtmlRenderer();
            $renderer->setRoundTripMode(true);
            $converter = new CarveConverter(renderer: $renderer);
            $converter->addExtension(new TabsExtension(mode: $mode));
            $converter->addExtension(FencedRenderExtension::chart());
            $converter->addExtension(FencedRenderExtension::mermaid());
            $result = (new HtmlToCarve())->convertWithReport($converter->convert($source));
            $this->assertSameTree($source, $result->value);
            $this->assertSame([], $result->report()['diagnostics']);
        }
    }

    public function testFenceConfigurationsRoundTripWithoutLoss(): void
    {
        $cases = [
            ['mermaid', FencedRenderExtension::mermaid(), 'graph TD;'],
            ['d2', FencedRenderExtension::d2(), 'a -> b'],
            ['wavedrom', FencedRenderExtension::wavedrom(), '{}'],
            ['abc', FencedRenderExtension::abc(), 'C D E'],
            ['chart', FencedRenderExtension::chart(), '{}'],
            ['mermaid', FencedRenderExtension::mermaid(wrapInFigure: true, tag: 'div', cssClass: 'diagram'), 'graph TD;'],
            ['custom', new FencedRenderExtension('custom', label: ''), 'a -> b'],
            ['vega-lite', FencedRenderExtension::vegaLite(), '{"url":"</script>"}'],
            ['graphviz', FencedRenderExtension::graphviz(), 'a -> b'],
            ['puml', FencedRenderExtension::plantuml(), 'a -> b'],
            ['custom', new FencedRenderExtension('custom', cssClass: 'diagram', tag: 'div'), 'a -> b'],
            ['chart', new FencedRenderExtension('chart', tag: 'pre', contentMode: FencedRenderExtension::MODE_JSON), '{"mark":"bar"}'],
        ];
        foreach ($cases as [$language, $extension, $content]) {
            foreach (['', ' [Diagram]'] as $label) {
                foreach ([false, true] as $static) {
                    $source = "```$language$label\n$content\n```\n";
                    $html = $this->export($source, clone $extension, $static);
                    $result = (new HtmlToCarve())->convertWithReport($html);
                    $this->assertSameTree($source, $result->value);
                    $this->assertSame([], $result->report()['diagnostics'], $html);
                }
            }
        }
    }

    public function testRebuiltExtensionScaffoldingReportsNoLoss(): void
    {
        foreach ([TabsExtension::MODE_CSS, TabsExtension::MODE_ARIA] as $mode) {
            foreach (["::: tab [First]\nBody.\n:::", "::: tab\n## First\n\nBody.\n:::"] as $panel) {
                $source = ":::: tabs\n$panel\n::::\n";
                $result = (new HtmlToCarve())->convertWithReport($this->export($source, new TabsExtension(mode: $mode)));
                $this->assertSameTree($source, $result->value);
                $this->assertSame([], $result->report()['diagnostics']);
            }
            $source = "::: code-group\n```php [Install]\na\n```\n:::";
            $result = (new HtmlToCarve())->convertWithReport($this->export($source, new CodeGroupExtension(mode: $mode)));
            $this->assertSameTree($source, $result->value);
            $this->assertSame([], $result->report()['diagnostics']);
        }
        $source = "```php [Install]\na() <1>\n```\n";
        $result = (new HtmlToCarve())->convertWithReport($this->export($source, new CodeCalloutsExtension()));
        $this->assertSameTree($source, $result->value);
        $this->assertSame([], $result->report()['diagnostics']);
    }

    public function testTabsRecoverTitleCarriersAndNestedContent(): void
    {
        $sources = [
            ":::: tabs\n::: tab [First]\nContent one.\n:::\n::::\n",
            ":::: tabs\n::: tab\n### First\n\nContent one.\n:::\n::::\n",
            ":::: tabs\n::: tab\nBody.\n:::\n{selected}\n::: tab [Second]\nOther.\n:::\n::::\n",
            ":::: tabs\n::: tab\n{#title .wide}\n## *First*\n\nContent one.\n:::\n::::\n",
            ":::: tabs\n::: tab\n## *First*\n\nContent one.\n:::\n::::\n",
            ":::::: tabs\n::::: tab [Outer]\n:::: tabs\n::: tab [Inner]\nBody.\n:::\n::::\n:::::\n::::::\n",
            ":::: tabs\n::: tab [First]\n```php [Install]\necho 1;\n```\n:::\n::::\n",
            "- ::::: tabs\n  :::: tab [First]\n  Content one.\n  ::::\n  :::::\n",
            ":::: tabs\n{#panel .wide label=First selected}\n::: tab\nBody.\n:::\n::::\n",
        ];
        foreach ([TabsExtension::MODE_CSS, TabsExtension::MODE_ARIA] as $mode) {
            foreach ($sources as $source) {
                $html = $this->export($source, new TabsExtension(mode: $mode));
                foreach (['safe', 'semantic', 'roundtrip'] as $importMode) {
                    $this->assertSameTree($source, (new HtmlToCarve(importMode: $importMode))->convert($html));
                }
            }
        }
    }

    public function testQuotedTabTitleReturnsAsHeader(): void
    {
        $source = ":::: tabs\n::: tab \"Title\" [First]\nContent.\n:::\n::::\n";
        foreach ([TabsExtension::MODE_CSS, TabsExtension::MODE_ARIA] as $mode) {
            $html = $this->export($source, new TabsExtension(mode: $mode));
            $this->assertSameTree($source, (new HtmlToCarve())->convert($html));
        }
    }

    public function testHeadingDerivedTabTitleKeepsItsPosition(): void
    {
        $source = ":::: tabs\n::: tab\nBefore.\n\n## Name\n\nAfter.\n:::\n::::\n";
        foreach ([TabsExtension::MODE_CSS, TabsExtension::MODE_ARIA] as $mode) {
            $html = $this->export($source, new TabsExtension(mode: $mode));
            $this->assertSameTree($source, (new HtmlToCarve())->convert($html));
        }
    }

    public function testStaticFenceFallbackKeepsItsLabel(): void
    {
        $source = "```mermaid [Diagram]\ngraph TD;\n```\n";
        $renderer = new HtmlRenderer();
        $renderer->setRoundTripMode(true);
        $converter = new CarveConverter(renderer: $renderer, mode: RenderMode::STATIC);
        $converter->addExtension(FencedRenderExtension::mermaid());
        $html = $converter->convert($source);
        $this->assertStringNotContainsString('data-carve-src', $html);
        $this->assertStringContainsString('data-carve-label="Diagram"', $html);
        $this->assertSameTree($source, (new HtmlToCarve())->convert($html));
    }

    public function testFirstSelectionIsNormalizedForTabsAndCodeGroups(): void
    {
        $cases = [
            [":::: tabs\n::: tab [First]\nBody.\n:::\n::::\n", ":::: tabs\n{selected}\n::: tab [First]\nBody.\n:::\n::::\n", TabsExtension::class],
            ["::: code-group\n```php\na\n```\n:::\n", "::: code-group\n{selected}\n```php\na\n```\n:::\n", CodeGroupExtension::class],
        ];
        foreach ([TabsExtension::MODE_CSS, TabsExtension::MODE_ARIA] as $mode) {
            foreach ($cases as [$implicit, $explicit, $extension]) {
                $html = $this->export($explicit, new $extension(mode: $mode));
                $this->assertSame($this->export($implicit, new $extension(mode: $mode)), $html);
                $back = (new HtmlToCarve())->convert($html);
                $this->assertStringNotContainsString('{selected}', $back);
                $this->assertSameTree($explicit, $back);
                $this->assertSameTree($implicit, $back);
            }
        }
    }

    public function testCodeGroupKeepsExplicitAndFallbackLabelsDistinct(): void
    {
        foreach ([CodeGroupExtension::MODE_CSS, CodeGroupExtension::MODE_ARIA] as $mode) {
            $source = "::: code-group\n```php\na\n```\n```php [php]\nb\n```\n``` [Other]\nc\n```\n:::\n";
            $html = $this->export($source, new CodeGroupExtension(mode: $mode));
            $this->assertSame(2, substr_count($html, 'data-carve-label='));
            $imported = (new HtmlToCarve())->convert($html);
            $this->assertSameTree($source, $imported);
            $node = (new HtmlToCarve())->convertToAst($html)['children'][0];
            $this->assertSame('admonition', $node['type']);
            $this->assertSame('code-group', $node['kind']);
        }
    }

    public function testCodeGroupKeepsANondefaultSelection(): void
    {
        foreach ([CodeGroupExtension::MODE_CSS, CodeGroupExtension::MODE_ARIA] as $mode) {
            $source = "::: code-group\n```php\na\n```\n{selected}\n```js\nb\n```\n:::\n";
            $html = $this->export($source, new CodeGroupExtension(mode: $mode));
            $this->assertSameTree($source, (new HtmlToCarve())->convert($html));
            $node = (new HtmlToCarve())->convertToAst($html)['children'][0];
            $this->assertSame('admonition', $node['type']);
            $this->assertSame('code-group', $node['kind']);
            $nodes = $node['children'];
            $this->assertArrayNotHasKey('selected', $nodes[0]['attrs']['keyValues'] ?? []);
            $this->assertSame('', $nodes[1]['attrs']['keyValues']['selected']);
        }
    }

    public function testCodeGroupKeepsAuthoredAttributes(): void
    {
        foreach ([CodeGroupExtension::MODE_CSS, CodeGroupExtension::MODE_ARIA] as $mode) {
            $source = "{#examples .wide}\n::: code-group\n```php [Install]\na\n```\n:::\n";
            $html = $this->export($source, new CodeGroupExtension(mode: $mode));
            $this->assertSameTree($source, (new HtmlToCarve())->convert($html));
            $node = (new HtmlToCarve())->convertToAst($html)['children'][0];
            $this->assertSame('admonition', $node['type']);
            $this->assertSame('code-group', $node['kind']);
            $this->assertSame('examples', $node['attrs']['id']);
            $this->assertSame(['wide'], $node['attrs']['classes']);
        }
    }

    public function testCalloutAndLiteralNumberRemainDistinct(): void
    {
        foreach (['a() <1>', 'a() 1'] as $line) {
            $source = "```js\n$line\n```\n";
            $html = $this->export($source, new CodeCalloutsExtension());
            $this->assertSameTree($source, (new HtmlToCarve())->convert($html));
        }
        $foreign = '<pre><code>a() <b class="callout" data-callout="2">1</b></code></pre>';
        $this->assertStringNotContainsString('<2>', (new HtmlToCarve())->convert($foreign));
    }

    public function testLabeledCalloutBlockKeepsItsLabel(): void
    {
        $source = "```php [Install]\na() <1>\n```\n";
        $html = $this->export($source, new CodeCalloutsExtension());
        $this->assertStringContainsString('data-carve-label="Install"', $html);
        $imported = (new HtmlToCarve())->convert($html);
        $this->assertSameTree($source, $imported);
    }

    public function testMermaidFenceAndClassedCodeRemainDistinct(): void
    {
        foreach (["```mermaid\ngraph TD;\n```\n", "{.mermaid}\n```\ngraph TD;\n```\n"] as $source) {
            $html = $this->export($source, FencedRenderExtension::mermaid());
            $this->assertSameTree($source, (new HtmlToCarve())->convert($html));
        }
    }

    public function testStaticMermaidContentIsReadInEveryMode(): void
    {
        $source = "```mermaid [Diagram]\ngraph TD;\nA-->B;\n```\n";
        $html = $this->export($source, FencedRenderExtension::mermaid(), true);
        $this->assertStringContainsString('data-carve-fence-content=', $html);
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $imported = (new HtmlToCarve(importMode: $mode))->convert($html);
            $this->assertSameTree($source, $imported);
        }
        $plain = $this->export($source, FencedRenderExtension::mermaid(), true, false);
        $this->assertStringNotContainsString('data-carve-fence-content=', $plain);
        $this->assertStringNotContainsString('graph TD;', (new HtmlToCarve())->convert($plain));
    }

    public function testStaticContentStaysTextAndKeepsAuthoredAttributes(): void
    {
        $source = "{#diagram .wide aria-label=\"Overview\"}\n```mermaid [View]\ngraph TD;\nA-->B;\n\n```\n";
        $html = $this->export($source, FencedRenderExtension::mermaid(), true);
        $node = (new HtmlToCarve())->convertToAst($html)['children'][0];
        $this->assertSame('mermaid', $node['lang']);
        $this->assertSame('View', $node['label']);
        $this->assertSame((new CarveConverter())->parse($source)->getChildren()[0]->getContent(), $node['content']);
        $this->assertSame('diagram', $node['attrs']['id']);
        $this->assertSame(['wide'], $node['attrs']['classes']);
        $this->assertSame('Overview', $node['attrs']['keyValues']['aria-label']);

        $html = '<div class="mermaid" role="img" aria-label="mermaid" data-carve-fence-language="mermaid" data-carve-fence-content="```&#10;&lt;script&gt;bad()&lt;/script&gt;"><svg></svg></div>';
        $node = (new HtmlToCarve())->convertToAst($html)['children'][0];
        $this->assertSame('code_block', $node['type']);
        $this->assertSame("```\n<script>bad()</script>", $node['content']);
        $this->assertStringContainsString('````mermaid', (new HtmlToCarve())->convert($html));
    }

    public function testBrokenCodeGroupWiringIsNotFlattened(): void
    {
        $source = "::: code-group\n```php [Install]\na\n```\n:::\n";
        $html = $this->export($source, new CodeGroupExtension());
        $html = str_replace('for="codegroup-1-tab-1"', 'for="other"', $html);
        $this->assertStringContainsString('code-group-panel', (new HtmlToCarve())->convert($html));
    }

    public function testForeignShapesDoNotBecomeExtensions(): void
    {
        $importer = new HtmlToCarve();
        $this->assertStringNotContainsString('```mermaid', $importer->convert('<pre class="mermaid">graph TD;</pre>'));
        $html = '<div class="mermaid" role="img" aria-label="mermaid" data-carve-fence-language="mermaid" data-carve-fence-content="secret"><p>foreign</p></div>';
        $this->assertStringNotContainsString('secret', $importer->convert($html));
        $html = str_replace('<p>foreign</p>', '<svg></svg>', $html);
        $html = str_replace('data-carve-fence-language="mermaid"', 'data-carve-fence-language="bad language"', $html);
        $this->assertStringNotContainsString('secret', $importer->convert($html));
    }

    public function testAnInteractiveMermaidFenceKeepsItsLabel(): void
    {
        $source = "```mermaid [Diagram]\ngraph TD;\n```\n";
        $html = $this->export($source, FencedRenderExtension::mermaid());
        $this->assertStringContainsString('data-carve-label="Diagram"', $html);
        $this->assertSameTree($source, (new HtmlToCarve())->convert($html));
    }

    public function testExtensionCarrierCollisionsAreReportedAndRebuilt(): void
    {
        $cases = [
            ["{data-carve-ext=x}\n:::: tabs\n::: tab [First]\nBody.\n:::\n::::\n", new TabsExtension(), 'data-carve-ext'],
            ["{data-carve-ext=x}\n::: code-group\n```php [Install]\na\n```\n:::\n", new CodeGroupExtension(), 'data-carve-ext'],
            [":::: tabs\n{data-carve-label=x}\n::: tab [First]\nBody.\n:::\n::::\n", new TabsExtension(), 'data-carve-label'],
            [":::: tabs\n::: tab\n{data-carve-tab-title=x}\n## First\n\nBody.\n:::\n::::\n", new TabsExtension(), 'data-carve-tab-title'],
            ["{data-carve-label=x}\n```php [Install]\na() <1>\n```\n", new CodeCalloutsExtension(), 'data-carve-label'],
        ];
        foreach (['data-carve-label', 'data-carve-fence-language', 'data-carve-fence-content'] as $name) {
            $cases[] = ["{{$name}=x}\n```chart [Diagram]\n{}\n```\n", FencedRenderExtension::chart(), $name];
        }
        foreach ($cases as [$source, $extension, $name]) {
            $renderer = new HtmlRenderer();
            $renderer->setRoundTripMode(true);
            $converter = new CarveConverter(renderer: $renderer);
            $converter->addExtension($extension);
            $report = $converter->convertWithReport($source);
            $this->assertSame(1, substr_count($report->value, $name), $report->value);
            $this->assertStringNotContainsString($name . '="x"', $report->value);
            $rows = array_values(array_filter($report->losses, static fn (array $row): bool => $row['code'] === 'carrier-attribute-dropped'));
            $this->assertCount(1, $rows);
            $this->assertSame($name, $rows[0]['attribute']);
            $clean = str_replace("{{$name}=x}\n", '', $source);
            $this->assertSameTree($clean, (new HtmlToCarve())->convert($report->value));
        }
    }

    public function testStaticFenceCarrierCollisionsAreReportedAndRebuilt(): void
    {
        foreach (['data-carve-label', 'data-carve-fence-language', 'data-carve-fence-content'] as $name) {
            $source = "{{$name}=x}\n```mermaid [Diagram]\ngraph TD;\n```\n";
            $renderer = new HtmlRenderer();
            $renderer->setRoundTripMode(true);
            $renderer->setStaticRenderers(['mermaid' => static fn (string $content): string => '<svg></svg>']);
            $converter = new CarveConverter(renderer: $renderer, mode: RenderMode::STATIC);
            $converter->addExtension(FencedRenderExtension::mermaid());
            $report = $converter->convertWithReport($source);
            $this->assertSame(1, substr_count($report->value, $name));
            $this->assertStringNotContainsString($name . '="x"', $report->value);
            $this->assertCount(1, $report->losses);
            $this->assertSame('carrier-attribute-dropped', $report->losses[0]['code']);
            $this->assertSame($name, $report->losses[0]['attribute']);
            $this->assertSameTree(str_replace("{{$name}=x}\n", '', $source), (new HtmlToCarve())->convert($report->value));
        }
    }

    public function testRecognizedElementsStillReportDroppedAuthoredAttributes(): void
    {
        $cases = [
            [":::: tabs\n::: tab [First]\nBody.\n:::\n::::\n", new TabsExtension()],
            [":::: tabs\n::: tab\n## First\n\nBody.\n:::\n::::\n", new TabsExtension(mode: 'aria')],
            ["::: code-group\n```php [Install]\na\n```\n:::\n", new CodeGroupExtension()],
            ["```d2\na -> b\n```\n", FencedRenderExtension::d2()],
            ["```chart\n{}\n```\n", FencedRenderExtension::chart()],
            ["```mermaid\ngraph TD;\n```\n", FencedRenderExtension::mermaid()],
            ["```php\na() <1>\n```\n", new CodeCalloutsExtension()],
        ];
        foreach ($cases as [$source, $extension]) {
            $html = $this->export($source, $extension);
            $html = preg_replace('/<(div|pre|input|label|button|h[1-6]|b)(?=[ >])/', '$0 onclick="bad()" formaction="javascript:bad()"', $html);
            $result = (new HtmlToCarve())->convertWithReport($html);
            $rows = $result->report()['diagnostics'];
            $this->assertSameTree($source, $result->value);
            $this->assertSame(substr_count($html, 'onclick='), count(array_filter($rows, static fn (array $row): bool => str_contains($row['message'], 'onclick'))), $html);
            $this->assertSame(substr_count($html, 'formaction='), count(array_filter($rows, static fn (array $row): bool => str_contains($row['message'], 'formaction'))), $html);
        }
    }

    public function testPanelsPreserveAuthoredHiddenAndConsumeGeneratedVisibility(): void
    {
        foreach (['css', 'aria'] as $mode) {
            foreach (['', "{hidden}\n"] as $attribute) {
                $source = ":::: tabs\n{$attribute}::: tab [First]\nBody.\n:::\n::: tab [Second]\nOther.\n:::\n::::\n";
                $html = $this->export($source, new TabsExtension(mode: $mode));
                $this->assertSameTree($source, (new HtmlToCarve())->convert($html));
            }
        }
    }
}
