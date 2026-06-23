#!/usr/bin/env php
<?php

/**
 * Static render mode demo
 *
 * Renders examples/static-render-demo.crv twice - once in the default
 * interactive mode, once in static mode (mode: RenderMode::STATIC) - and writes
 * both HTML outputs. The same document, the same extensions; only the render
 * mode differs.
 *
 *   php examples/static-render-demo.php
 *
 * In static mode tabs / code-group flatten to labeled <section>s, and the math
 * / mermaid blocks degrade to server-rendered output (if a renderer is
 * supplied) or readable source (the default below shows the source fallback).
 *
 * Pass a `renderers` map to render math / mermaid at build time, e.g.
 *
 *   new CarveConverter(mode: RenderMode::STATIC, renderers: [
 *       'math' => fn (string $tex): string => $katex->renderToString($tex),
 *       'mermaid' => fn (string $src): string => $mmdc->renderSvg($src),
 *   ]);
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Carve\CarveConverter;
use Carve\Extension\CodeGroupExtension;
use Carve\Extension\FencedRenderExtension;
use Carve\Extension\MathBlockExtension;
use Carve\Extension\TabsExtension;
use Carve\Renderer\RenderMode;

$source = (string)file_get_contents(__DIR__ . '/static-render-demo.crv');

/**
 * Build a converter in the requested mode with the bundled interactive
 * extensions registered.
 *
 * @param string $mode RenderMode::INTERACTIVE or RenderMode::STATIC.
 * @param array<string, \Closure(string): string> $renderers Build-time renderers (static mode).
 */
function buildConverter(string $mode, array $renderers = []): CarveConverter
{
    $converter = new CarveConverter(mode: $mode, renderers: $renderers);
    $converter->addExtension(new TabsExtension());
    $converter->addExtension(new CodeGroupExtension());
    $converter->addExtension(new MathBlockExtension());
    $converter->addExtension(FencedRenderExtension::mermaid());

    return $converter;
}

$interactive = buildConverter(RenderMode::INTERACTIVE)->convert($source);
$static = buildConverter(RenderMode::STATIC)->convert($source);

$outDir = __DIR__ . '/output';
if (!is_dir($outDir)) {
    mkdir($outDir, 0o755, true);
}

file_put_contents($outDir . '/static-render-demo.interactive.html', $interactive);
file_put_contents($outDir . '/static-render-demo.static.html', $static);

echo "Wrote:\n";
echo "  examples/output/static-render-demo.interactive.html\n";
echo "  examples/output/static-render-demo.static.html\n\n";

echo "=== INTERACTIVE (excerpt) ===\n";
echo $interactive;
echo "\n=== STATIC (excerpt) ===\n";
echo $static;
