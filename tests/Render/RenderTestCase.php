<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use MarkupCarve\Carve\Html\HtmlRenderer;
use MarkupCarve\Carve\Json\TreeDecoder;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Options;
use MarkupCarve\Carve\Resolve\Resolver;
use PHPUnit\Framework\TestCase;

/**
 * Builds trees as JSON, so the render tests do not depend on the parsers.
 */
abstract class RenderTestCase extends TestCase
{
    /**
     * @param string $value
     *
     * @return array<string, mixed>
     */
    protected static function t(string $value): array
    {
        return ['type' => 'text', 'value' => $value];
    }

    /**
     * @param array<string, mixed> ...$children
     *
     * @return array<string, mixed>
     */
    protected static function p(array ...$children): array
    {
        return ['type' => 'paragraph', 'children' => $children];
    }

    /**
     * @param array<string, mixed> ...$children
     *
     * @return array<string, mixed>
     */
    protected static function doc(array ...$children): array
    {
        return ['type' => 'document', 'astVersion' => '2.0', 'children' => $children];
    }

    /**
     * @param array<string, mixed> $tree
     *
     * @return \MarkupCarve\Carve\Node
     */
    protected static function node(array $tree): Node
    {
        $node = TreeDecoder::fromArray($tree);
        self::assertNotNull($node);

        return $node;
    }

    /**
     * Resolves and renders a tree.
     *
     * @param array<string, mixed> $tree
     * @param array<string, mixed> $options
     *
     * @return string
     */
    protected static function html(array $tree, array $options = []): string
    {
        $opts = Options::from($options);
        $doc = self::node($tree);
        Resolver::resolve($doc, null, $opts);

        return HtmlRenderer::render($doc, $opts);
    }
}
