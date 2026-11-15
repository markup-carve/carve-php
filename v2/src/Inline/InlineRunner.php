<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Inline;

use MarkupCarve\Carve2\Attrs;
use MarkupCarve\Carve2\Node;

/**
 * Runs the inline pass over every leaf the block pass left with content line spans (C2-I-001).
 */
final class InlineRunner
{
    /**
     * Containers that count toward the depth cap (C2-B-190).
     *
     * @var array<string, true>
     */
    private const DEPTH_TYPES = [
        'block_quote' => true,
        'list' => true,
        'list_item' => true,
        'definition_list' => true,
        'definition_description' => true,
        'footnote' => true,
        'div' => true,
        'admonition' => true,
        'directive' => true,
    ];

    /**
     * @param \MarkupCarve\Carve2\Node $doc
     * @param string $s
     * @param \MarkupCarve\Carve2\Inline\InlineContext $ctx
     *
     * @return void
     */
    public static function run(Node $doc, string $s, InlineContext $ctx): void
    {
        // The pass allocates one array per token; cycle collection over them is quadratic on long contents.
        $gc = gc_enabled();
        gc_disable();
        try {
            self::walk($doc, $s, $ctx);
        } finally {
            if ($gc) {
                gc_enable();
            }
        }
    }

    /**
     * @param \MarkupCarve\Carve2\Node $doc
     * @param string $s
     * @param \MarkupCarve\Carve2\Inline\InlineContext $ctx
     *
     * @return void
     */
    private static function walk(Node $doc, string $s, InlineContext $ctx): void
    {
        $stack = [[$doc, 0, false, false]];
        while ($stack !== []) {
            [$node, $depth, $hard, $caption] = array_pop($stack);
            if ($node->lines !== null) {
                $node->children = self::parse($s, $node->lines, $ctx, $depth, self::mode($node->type, $hard, $caption));
                $node->lines = null;

                continue;
            }
            $inner = $depth + (isset(self::DEPTH_TYPES[$node->type]) ? 1 : 0);
            $attrs = $node->f['attrs'] ?? null;
            if ($node->type === 'div' && $attrs instanceof Attrs && in_array('hardbreaks', $attrs->classes, true)) {
                $hard = true;
            }
            foreach ($node->children ?? [] as $child) {
                $stack[] = [$child, $inner, $hard, $node->type === 'caption'];
            }
            foreach (['caption', 'title'] as $slot) {
                $value = $node->f[$slot] ?? null;
                if ($value instanceof Node) {
                    $stack[] = [$value, $inner, $hard, false];
                }
            }
        }
    }

    /**
     * @param string $type
     * @param bool $hard
     * @param bool $caption
     *
     * @return int
     */
    private static function mode(string $type, bool $hard, bool $caption): int
    {
        return ($type === 'line' ? Scanner::LINE_BLOCK : 0) | ($hard ? Scanner::HARD_BREAKS : 0)
        | ($caption ? Scanner::CAPTION : 0) | ($type === 'cell_paragraph' ? Scanner::CELL : 0);
    }

    /**
     * The inline nodes of one content.
     *
     * @param string $s
     * @param array<int> $spans
     * @param \MarkupCarve\Carve2\Inline\InlineContext $ctx
     * @param int $depth
     * @param int $mode Bits of the Scanner mode constants.
     *
     * @return array<\MarkupCarve\Carve2\Node>
     */
    public static function parse(
        string $s,
        array $spans,
        InlineContext $ctx,
        int $depth = 0,
        int $mode = 0,
    ): array {
        $content = new Content($s, $spans);
        if ($content->t === '') {
            return [];
        }
        $scanner = new Scanner($content, $ctx, $depth, $mode);
        $items = Delimiters::run($scanner, $scanner->scan(), $depth);

        return (new Finisher($scanner, $ctx))->build($items, -1, true);
    }
}
