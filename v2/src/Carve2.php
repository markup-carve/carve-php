<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2;

use MarkupCarve\Carve2\Block\BlockParser;
use MarkupCarve\Carve2\Block\TreeSink;
use MarkupCarve\Carve2\Diagnostics\Diagnostic;
use MarkupCarve\Carve2\Diagnostics\Findings;
use MarkupCarve\Carve2\Html\HtmlRenderer;
use MarkupCarve\Carve2\Include\Expander;
use MarkupCarve\Carve2\Inline\InlineContext;
use MarkupCarve\Carve2\Inline\InlineRunner;
use MarkupCarve\Carve2\Json\TreeEncoder;
use MarkupCarve\Carve2\Resolve\Resolver;

/**
 * The public entry: parse (source to tree), resolve, render (tree to HTML).
 */
final class Carve2
{
    /**
     * Parse and resolve: the tree of a source.
     *
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return \MarkupCarve\Carve2\Node
     */
    public static function parse(string $source, array $options = []): Node
    {
        return self::parsed($source, Options::from($options))[0];
    }

    /**
     * Parse and resolve, with the entries of the parse channel (C2-A-173).
     *
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return \MarkupCarve\Carve2\ParseResult
     */
    public static function parseWithDiagnostics(string $source, array $options = []): ParseResult
    {
        [$doc, $findings] = self::parsed($source, Options::from($options));

        return new ParseResult($doc, $findings->finish($doc));
    }

    /**
     * Render, with the entries of the parse and the render-loss channels (C2-A-173).
     *
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return \MarkupCarve\Carve2\RenderResult
     */
    public static function renderWithDiagnostics(string $source, array $options = []): RenderResult
    {
        $opts = Options::from($options);
        [$doc, $findings] = self::parsed($source, $opts);
        [$html, $log] = HtmlRenderer::renderWithLog($doc, $opts, $source);
        $entries = $findings->finish($doc);
        foreach ($log->entries as $entry) {
            $severity = $opts->strictLosses ? 'error' : $entry['severity'];
            $entries[] = new Diagnostic($entry['code'], $severity, $entry['channel'], $entry['message'], file: $entry['file']);
        }

        return new RenderResult($html, $entries);
    }

    /**
     * @param string $source
     * @param \MarkupCarve\Carve2\Options $opts
     *
     * @return array{0: \MarkupCarve\Carve2\Node, 1: \MarkupCarve\Carve2\Diagnostics\Findings}
     */
    private static function parsed(string $source, Options $opts): array
    {
        $gc = self::collectorOff();
        try {
            [$doc, $findings, $hasInclude] = self::stages($source);
            self::expand($doc, $findings, $opts, $hasInclude);
            Resolver::resolve($doc, $source, $opts, false, $findings);

            return [$doc, $findings];
        } finally {
            if ($gc) {
                gc_enable();
            }
        }
    }

    /**
     * The tree is acyclic and freed whole; the cycle collector would rescan it on every allocation
     * burst, which made large documents superlinear. The caller restores the state.
     *
     * @return bool Whether the collector was on.
     */
    private static function collectorOff(): bool
    {
        $gc = gc_enabled();
        gc_disable();

        return $gc;
    }

    /**
     * Parse without resolving.
     *
     * @param string $source
     * @param array<string, string> $inheritedAbbreviations
     *
     * @return array{0: \MarkupCarve\Carve2\Node, 1: \MarkupCarve\Carve2\Diagnostics\Findings, 2: bool}
     */
    private static function stages(string $source, array $inheritedAbbreviations = []): array
    {
        $sink = new TreeSink($source);
        (new BlockParser($sink))->parse($source);
        $doc = $sink->root;
        assert($doc !== null);
        $ctx = InlineContext::collect($doc, $source);
        foreach ($inheritedAbbreviations as $key => $expansion) {
            $ctx->abbreviations[$key] ??= $expansion;
        }
        InlineRunner::run($doc, $source, $ctx);
        if (str_contains($source, "\0") || !mb_check_encoding($source, 'UTF-8')) {
            NulReplacer::apply($doc);
        }

        return [$doc, $sink->findings, $sink->hasInclude];
    }

    private static function expand(Node $doc, Findings $findings, Options $opts, bool $hasInclude): void
    {
        if ($opts->includeResolver !== null) {
            $doc->f['stages'] = ['includeExpansion'];
        }
        if (!$hasInclude) {
            return;
        }
        (new Expander($opts, $findings, self::stages(...)))->expand($doc);
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public static function toHtml(string $source, array $options = []): string
    {
        $opts = Options::from($options);
        $gc = self::collectorOff();
        try {
            [$doc, , $hasInclude] = self::stages($source);
            self::expand($doc, new Findings(), $opts, $hasInclude);
            // The resolve hands its lists to the renderer, which then skips its own walk of the tree.
            $facts = Resolver::resolve($doc, $source, $opts, true)->facts;
        } finally {
            if ($gc) {
                gc_enable();
            }
        }

        return HtmlRenderer::render($doc, $opts, $source, $facts);
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public static function toJson(string $source, array $options = []): string
    {
        return TreeEncoder::json(self::parse($source, $options), $source);
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return array<array{path: string, from: string|null, file: string|null, read: bool}>
     */
    public static function includeDependencies(string $source, array $options = []): array
    {
        $opts = Options::from($options);
        $doc = self::parse($source, $options);
        $out = [];
        $stack = [[$doc, $opts->includeFile]];
        while ($stack !== []) {
            [$node, $from] = array_pop($stack);
            if ($node->type === 'include') {
                $file = is_string($node->f['file'] ?? null) ? $node->f['file'] : null;
                $out[] = [

                    'path' => is_string($node->f['path'] ?? null) ? $node->f['path'] : '',
                    'from' => $from,
                    'file' => $file,
                    'read' => $file !== null,
                ];
                if ($file !== null) {
                    $from = $file;
                }
            }
            foreach (array_reverse($node->children ?? []) as $child) {
                $stack[] = [$child, $from];
            }
        }

        return $out;
    }
}
