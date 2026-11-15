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
                if ($node->type === 'cell_paragraph' && $node->start < 0) {
                    // A cell of a row with continuation rows has no position, and its nodes have none (C2-A-158).
                    $node->children = self::joined($node->children);
                }

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
            $caption = $node->f['caption'] ?? null;
            if ($caption instanceof Node) {
                $stack[] = [$caption, $inner, $hard, false];
            }
            $title = $node->f['title'] ?? null;
            if ($title instanceof Node) {
                $node->f['title'] = self::title($node, $title, $s, $ctx, $inner, $hard);
            }
        }
    }

    /**
     * C2-A-024, ast-schema.json: the title slot is an inline list; the carrier node of the block pass goes.
     *
     * @param \MarkupCarve\Carve2\Node $node
     * @param \MarkupCarve\Carve2\Node $title
     * @param string $s
     * @param \MarkupCarve\Carve2\Inline\InlineContext $ctx
     * @param int $depth
     * @param bool $hard
     *
     * @return array<\MarkupCarve\Carve2\Node>
     */
    private static function title(Node $node, Node $title, string $s, InlineContext $ctx, int $depth, bool $hard): array
    {
        if ($title->lines === null) {
            return $title->children ?? [];
        }

        return $node->type === 'code_block'
            ? self::plainTitle($s, $title->lines)
            : self::parse($s, $title->lines, $ctx, $depth, self::mode('title', $hard, false));
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
        $t = $content->t;
        if ($t === '') {
            return [];
        }
        $len = strlen($t);
        if ($ctx->abbreviations === [] && strcspn($t, Scanner::SPECIALS) === $len && !Finisher::replaces($t)) {
            return [Node::text($t, $content->src(0), $content->src($len))];
        }
        $scanner = new Scanner($content, $ctx, $depth, $mode);
        $items = Delimiters::run($scanner, $scanner->scan(), $depth);

        $nodes = (new Finisher($scanner, $ctx))->build($items, -1, true);

        return ($mode & Scanner::CELL) !== 0 && count($spans) > 2 ? self::joined($nodes) : $nodes;
    }

    /**
     * G-inline-6: the quoted title of a code fence is one text, its escapes read as in a quoted value
     * (C2-I-033); a text that lost a backslash has no position (C2-A-158).
     *
     * @param string $s
     * @param array<int> $spans
     *
     * @return array<\MarkupCarve\Carve2\Node>
     */
    private static function plainTitle(string $s, array $spans): array
    {
        $start = $spans[0] ?? 0;
        $end = $spans[count($spans) - 1] ?? $start;
        $raw = substr($s, $start, max(0, $end - $start));
        if ($raw === '') {
            return [];
        }
        // C2-I-033: one escape and one character class, linear; code fence titles only, not a hot path.
        $value = (string)preg_replace('/\\\\([!-\/:-@\[-`{-~])/', '$1', $raw);

        return [$value === $raw ? Node::text($value, $start, $end) : Node::text($value, -1, -1)];
    }

    /**
     * C2-B-184, C2-A-094, C2-A-158: in a cell joined from continuation rows each join is one space of
     * text and the inline nodes carry no position.
     *
     * @param array<\MarkupCarve\Carve2\Node> $nodes
     *
     * @return array<\MarkupCarve\Carve2\Node>
     */
    private static function joined(array $nodes): array
    {
        $root = new Node('cell', $nodes);
        $stack = [$root];
        while ($stack !== []) {
            $node = array_pop($stack);
            $out = [];
            $children = $node->children ?? [];
            $lastKey = array_key_last($children);
            foreach ($children as $key => $child) {
                $child->start = -1;
                $child->end = -1;
                // C2-I-002: a backslash at the end of the cell stays a hard break.
                $atEnd = $node === $root && $key === $lastKey;
                if ($child->type === 'soft_break' || ($child->type === 'hard_break' && !$atEnd)) {
                    $value = $child->type === 'soft_break' ? ' ' : '\\ ';
                    $child = new Node('text');
                    $child->f['value'] = $value;
                }
                $last = $out === [] ? null : $out[count($out) - 1];
                $a = $last !== null && $last->type === 'text' ? $last->f['value'] ?? '' : null;
                $b = $child->f['value'] ?? '';
                if ($child->type === 'text' && $last !== null && is_string($a) && is_string($b)) {
                    $last->f['value'] = $a . $b;

                    continue;
                }
                $out[] = $child;
                if ($child->children !== null) {
                    $stack[] = $child;
                }
            }
            $node->children = $out;
        }

        return $root->children ?? [];
    }
}
