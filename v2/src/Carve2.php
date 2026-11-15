<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2;

use MarkupCarve\Carve2\Block\BlockParser;
use MarkupCarve\Carve2\Block\TreeSink;
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
        $opts = Options::from($options);
        // The tree is acyclic and freed whole; the cycle collector would rescan it on every
        // allocation burst, which made large documents superlinear. The state is restored.
        $gc = gc_enabled();
        gc_disable();
        try {
            return self::stages($source, $opts);
        } finally {
            if ($gc) {
                gc_enable();
            }
        }
    }

    /**
     * @param string $source
     * @param \MarkupCarve\Carve2\Options $opts
     *
     * @return \MarkupCarve\Carve2\Node
     */
    private static function stages(string $source, Options $opts): Node
    {
        $sink = new TreeSink($source);
        (new BlockParser($sink))->parse($source);
        $doc = $sink->root;
        assert($doc !== null);
        $ctx = InlineContext::collect($doc, $source);
        InlineRunner::run($doc, $source, $ctx);
        if (str_contains($source, "\0")) {
            NulReplacer::apply($doc);
        }
        Resolver::resolve($doc, $source, $opts);

        return $doc;
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

        return HtmlRenderer::render(self::parse($source, $options), $opts, $source);
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
