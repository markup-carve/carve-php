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

        return preg_replace('/ data-carve-src="[^"]*"/', '', $converter->convert($source)) ?? '';
    }

    /**
     * The import must rebuild the source's tree; spelling the canonical writer chooses (fence padding, blank
     * lines between a group's blocks) is not part of the claim.
     */
    private function assertSameTree(string $expected, string $actual): void
    {
        $tree = static fn (string $carve): array => array_diff_key((new AstCodec())->encode((new BlockParser())->parse($carve)), ['srcByteLength' => true]);
        $this->assertSame($tree($expected), $tree($actual), $actual);
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
}
