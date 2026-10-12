<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Extension\AdmonitionExtension;
use MarkupCarve\Carve\Extension\CodeCalloutsExtension;
use MarkupCarve\Carve\Extension\CodeGroupExtension;
use MarkupCarve\Carve\Extension\FencedRenderExtension;
use MarkupCarve\Carve\Extension\TabsExtension;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\RenderMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExtensionCompositionMatrixTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function compositionProvider(): array
    {
        $constructs = [];
        foreach (['', ' [Code]'] as $label) {
            foreach (['', "{#code .c key=v}\n"] as $attrs) {
                $constructs['plain-' . ($label === '' ? 'unlabeled' : 'labeled') . '-' . ($attrs === '' ? 'bare' : 'attrs')] = $attrs . "```php$label\necho 1;\n```\n";
            }
        }
        $constructs += [
            'mermaid' => "```mermaid [Diagram]\ngraph TD; A-->B\n```\n",
            'd2' => "```d2\na -> b\n```\n",
            'json' => "```chart\n{\"url\":\"</script>\"}\n```\n",
            'figure' => "```figure\na -> b\n```\n",
            'callouts' => "```php [Code]\necho 1; <1>\n```\n",
            'hidden-heading' => "{hidden}\n## Hidden\n",
            'id-heading' => "{#heading}\n## Heading\n",
            'hidden-id-heading' => "{hidden #heading}\n## Heading\n",
            'presentation' => "{role=\"presentation\"}\nParagraph.\n",
        ];
        $cases = [];
        foreach ($constructs as $name => $inner) {
            $hosts = [
                'top' => $inner,
                'label-tab' => ":::: tabs\n::: tab [Title]\n$inner\n:::\n::: tab [Second]\nOther.\n:::\n::::\n",
                'heading-tab' => ":::: tabs\n::: tab\n$inner\n## Title\n\n:::\n::: tab [Second]\nOther.\n:::\n::::\n",
                'quoted-tab' => ":::: tabs\n::: tab \"Title\"\n$inner\n:::\n::: tab [Second]\nOther.\n:::\n::::\n",
                'list' => '- ' . str_replace("\n", "\n  ", rtrim($inner)) . "\n",
                'quote' => '> ' . str_replace("\n", "\n> ", rtrim($inner)) . "\n",
                'nested-tab' => ":::::: tabs\n::::: tab [Outer]\n:::: tabs\n::: tab\n$inner\n## Title\n\n:::\n::::\n:::::\n::::::\n",
            ];
            // No parser-based skips. The code-group host applies only to code blocks;
            // headings and paragraphs are outside that host's requested matrix.
            if (!str_contains($name, 'heading') && $name !== 'presentation') {
                $hosts['code-group'] = "::: code-group\n$inner\n```js [Second]\nother();\n```\n:::\n";
            }
            foreach ($hosts as $host => $source) {
                foreach (['css', 'aria'] as $mode) {
                    foreach ([RenderMode::INTERACTIVE, RenderMode::STATIC] as $renderMode) {
                        $cases["$name/$host/$mode/$renderMode"] = [$source, $mode, $renderMode];
                    }
                }
            }
        }

        foreach (['aria-label="Custom"', 'hidden', '#id', '.class', 'role="region"', 'key=value'] as $attribute) {
            $hosts = [
                'tab' => ":::: tabs\n{{$attribute}}\n::: tab [Title]\nBody.\n:::\n::: tab [Second]\nOther.\n:::\n::::\n",
                'tabs' => "{{$attribute}}\n:::: tabs\n::: tab [Title]\nBody.\n:::\n::: tab [Second]\nOther.\n:::\n::::\n",
                'code-group' => "{{$attribute}}\n::: code-group\n```php [First]\necho 1;\n```\n```js [Second]\nother();\n```\n:::\n",
            ];
            foreach ($hosts as $host => $source) {
                foreach (['css', 'aria'] as $mode) {
                    foreach ([RenderMode::INTERACTIVE, RenderMode::STATIC] as $renderMode) {
                        $cases["authored-$attribute/$host/$mode/$renderMode"] = [$source, $mode, $renderMode];
                    }
                }
            }
        }

        return $cases;
    }

    #[DataProvider('compositionProvider')]
    public function testCompositionReconstructsTheTreeWithoutDiagnostics(string $source, string $mode, string $renderMode): void
    {
        $renderer = new HtmlRenderer();
        $renderer->setRoundTripMode(true);
        $renderer->setStaticRenderers(array_fill_keys(['mermaid', 'd2', 'chart', 'figure'], static fn (string $content): string => '<svg><text>diagram</text></svg>'));
        $converter = new CarveConverter(renderer: $renderer, mode: $renderMode);
        $converter->addExtension(new TabsExtension(mode: $mode));
        $converter->addExtension(new CodeGroupExtension(mode: $mode));
        $converter->addExtension(FencedRenderExtension::mermaid());
        $converter->addExtension(FencedRenderExtension::d2());
        $converter->addExtension(FencedRenderExtension::chart());
        $converter->addExtension(new FencedRenderExtension('figure', wrapInFigure: true));
        $converter->addExtension(new CodeCalloutsExtension());
        $converter->addExtension(new AdmonitionExtension());
        $html = $converter->convert($source);
        $result = (new HtmlToCarve())->convertWithReport($html);
        $tree = static fn (string $text): array => array_diff_key((new AstCodec())->encode((new BlockParser())->parse($text)), ['srcByteLength' => true]);
        $this->assertSame($tree($source), $tree($result->value), $html . "\n" . $result->value);
        $this->assertSame([], $result->report()['diagnostics'], $html);
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>, string}>
     */
    public static function optionsProvider(): array
    {
        $options = [
            'tabs' => [[], ['groupLabel' => ''], ['groupLabel' => 'Custom group'], ['wrapperClass' => 'custom-wrapper'], ['tabClass' => 'custom-panel'], ['labelClass' => 'custom-label'], ['radioClass' => 'custom-radio'], ['idPrefix' => 'custom-id']],
            'code-group' => [[], ['groupLabel' => ''], ['groupLabel' => 'Custom group'], ['wrapperClass' => 'custom-wrapper'], ['panelClass' => 'custom-panel'], ['labelClass' => 'custom-label'], ['radioClass' => 'custom-radio'], ['idPrefix' => 'custom-id'], ['highlighter' => static fn (string $code): string => '<pre><code>' . htmlspecialchars($code) . '</code></pre>'], ['highlighter' => static fn (string $code): string => htmlspecialchars($code)], ['highlighter' => static fn (string $code): string => '<div>' . htmlspecialchars($code) . '</div>']],
            'fenced' => [[], ['language' => ['diagram', 'alias']], ['cssClass' => 'custom-fence'], ['tag' => 'div'], ['contentMode' => 'json'], ['wrapInFigure' => true], ['wrapInFigure' => true, 'figureClass' => 'custom-figure'], ['wrapInFigure' => true, 'figureClass' => ''], ['label' => 'Custom label'], ['label' => '']],
            'mermaid' => [[], ['wrapInFigure' => true], ['tag' => 'div'], ['cssClass' => 'custom-fence'], ['wrapInFigure' => true, 'figureClass' => 'custom-figure']],
            'd2' => [[]],
            'graphviz' => [[]],
            'wavedrom' => [[]],
            'abc' => [[]],
            'plantuml' => [[]],
            'vegaLite' => [[]],
            'chart' => [[]],
            'callouts' => [[]],
        ];
        $cases = [];
        foreach ($options as $extension => $variants) {
            foreach ($variants as $index => $variant) {
                foreach (['aria-labelledby=external', '.custom role=region', 'hidden', '#id'] as $attribute) {
                    foreach (['css', 'aria'] as $mode) {
                        foreach ([RenderMode::INTERACTIVE, RenderMode::STATIC] as $renderMode) {
                            $attrs = "{{$attribute}}\n";
                            $code = $attrs . "```php [Code]\necho 1; <1>\n```\n";
                            $source = match ($extension) {
                                'tabs' => $attrs . ":::: tabs\n" . $attrs . "::: tab [First]\nBody.\n:::\n::: tab [Second]\nOther.\n:::\n::::\n",
                                'code-group' => $attrs . "::: code-group\n$code\n```js [Second]\nother();\n```\n:::\n",
                                'fenced' => $attrs . "```diagram [Diagram]\n{\"a\":\"<b>\"}\n```\n",
                                'mermaid' => $attrs . "```mermaid [Diagram]\ngraph TD; A-->B\n```\n",
                                'd2', 'graphviz', 'wavedrom', 'abc', 'plantuml', 'vegaLite', 'chart' => $attrs . '```' . ($extension === 'vegaLite' ? 'vega-lite' : $extension) . " [Diagram]\n{\"a\":1}\n```\n",
                                default => $code,
                            };
                            $variantOptions = in_array($extension, ['tabs', 'code-group'], true) ? $variant + ['mode' => $mode] : $variant;
                            $cases["$extension/$index/$attribute/$mode/$renderMode"] = [$source, $extension, $variantOptions, $renderMode];
                        }
                    }
                }
            }
        }

        return $cases;
    }

    /**
     * @param string $source
     * @param string $extension
     * @param array<string, mixed> $options
     * @param string $renderMode
     */
    #[DataProvider('optionsProvider')]
    public function testOptionsReconstructTheTreeWithoutDiagnostics(string $source, string $extension, array $options, string $renderMode): void
    {
        $converter = new CarveConverter(roundTripMode: true, mode: $renderMode);
        $converter->addExtension(match ($extension) {
            'tabs' => new TabsExtension(...$options),
            'code-group' => new CodeGroupExtension(...$options),
            'fenced' => new FencedRenderExtension(...($options + ['language' => 'diagram'])),
            'mermaid' => FencedRenderExtension::mermaid(...$options),
            'd2' => FencedRenderExtension::d2(),
            'graphviz' => FencedRenderExtension::graphviz(),
            'wavedrom' => FencedRenderExtension::wavedrom(),
            'abc' => FencedRenderExtension::abc(),
            'plantuml' => FencedRenderExtension::plantuml(),
            'vegaLite' => FencedRenderExtension::vegaLite(),
            'chart' => FencedRenderExtension::chart(),
            default => new CodeCalloutsExtension(),
        });
        $html = $converter->convert($source);
        $result = (new HtmlToCarve())->convertWithReport($html);
        $tree = static fn (string $text): array => array_diff_key((new AstCodec())->encode((new BlockParser())->parse($text)), ['srcByteLength' => true]);
        $this->assertSame($tree($source), $tree($result->value), $html . "\n" . $result->value);
        $this->assertSame([], $result->report()['diagnostics'], $html);
    }

    public function testMarkedExtensionsDoNotRequireGeneratedNamingAttributes(): void
    {
        foreach (['css', 'aria'] as $mode) {
            foreach ([RenderMode::INTERACTIVE, RenderMode::STATIC] as $renderMode) {
                $converter = new CarveConverter(roundTripMode: true, mode: $renderMode);
                $converter->addExtension(new TabsExtension(mode: $mode));
                $converter->addExtension(new CodeGroupExtension(mode: $mode));
                $converter->addExtension(FencedRenderExtension::mermaid());
                $source = ":::: tabs\n::: tab [Title]\nBody.\n:::\n::::\n\n::: code-group\n```php [Code]\necho 1;\n```\n:::\n\n```mermaid\ngraph TD; A-->B\n```\n";
                $html = preg_replace('/ (?:class(?!="language-)|role|aria-label|aria-labelledby|aria-controls|type|name|for)="[^"]*"/', '', $converter->convert($source));
                $this->assertIsString($html);
                $result = (new HtmlToCarve())->convertWithReport($html);
                $tree = static fn (string $text): array => array_diff_key((new AstCodec())->encode((new BlockParser())->parse($text)), ['srcByteLength' => true]);
                $this->assertSame($tree($source), $tree($result->value), $html);
                $this->assertSame([], $result->report()['diagnostics'], $html);
            }
        }
    }

    public function testUnmarkedMermaidHtmlKeepsItsClass(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<pre class="mermaid" role="img" aria-label="Diagram">graph TD; A--&gt;B</pre>');
        $tree = (new AstCodec())->encode((new BlockParser())->parse($result->value));
        $this->assertArrayNotHasKey('lang', $tree['children'][0]);
        $this->assertSame(['mermaid'], $tree['children'][0]['attrs']['classes']);
        $this->assertSame([], $result->report()['diagnostics']);
    }

    public function testRejectedTabGroupLeavesEarlierTitleAttributesIntact(): void
    {
        foreach (['css', 'aria'] as $mode) {
            $converter = new CarveConverter(roundTripMode: true);
            $converter->addExtension(new TabsExtension(mode: $mode));
            $html = $converter->convert(":::: tabs\n::: tab\n## First\n\nBody.\n:::\n::: tab [Second]\nOther.\n:::\n::::\n");
            $html = preg_replace('/ data-carve-explicit-id="0" id="First[^"]*"/', ' id="retained-anchor"', $html);
            $this->assertIsString($html);
            $role = $mode === 'aria' ? 'tabpanel' : 'group';
            $offset = strrpos($html, 'role="' . $role . '"');
            $this->assertNotFalse($offset);
            $html = substr_replace($html, 'role="invalid"', $offset, strlen('role="' . $role . '"'));
            $html = substr_replace($html, '<div>Extra</div>', strrpos($html, '</div>'), 0);
            foreach (['safe', 'roundtrip'] as $importMode) {
                $source = (new HtmlToCarve(importMode: $importMode))->convert($html);
                $this->assertStringContainsString('hidden', $source);
                $this->assertStringContainsString('#retained-anchor', $source);
                $this->assertStringContainsString('role=invalid', $source);
            }
        }
    }
}
