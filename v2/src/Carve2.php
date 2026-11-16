<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2;

use MarkupCarve\Carve2\Block\BlockParser;
use MarkupCarve\Carve2\Block\TreeSink;
use MarkupCarve\Carve2\Diagnostics\Diagnostic;
use MarkupCarve\Carve2\Html\HtmlRenderer;
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
            $entries[] = new Diagnostic($entry['code'], $entry['severity'], $entry['channel'], $entry['message']);
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
            [$doc, $findings] = self::stages($source);
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
     *
     * @return array{0: \MarkupCarve\Carve2\Node, 1: \MarkupCarve\Carve2\Diagnostics\Findings}
     */
    private static function stages(string $source): array
    {
        $sink = new TreeSink($source);
        (new BlockParser($sink))->parse($source);
        $doc = $sink->root;
        assert($doc !== null);
        $ctx = InlineContext::collect($doc, $source);
        InlineRunner::run($doc, $source, $ctx);
        if (str_contains($source, "\0") || !mb_check_encoding($source, 'UTF-8')) {
            NulReplacer::apply($doc);
        }

        return [$doc, $sink->findings];
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
            $doc = self::stages($source)[0];
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
}
