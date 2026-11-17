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
        $stack = [[$doc, false, false]];
        while ($stack !== []) {
            [$node, $hard, $caption] = array_pop($stack);
            if ($node->lines !== null && isset($node->layout['wrapAt'])) {
                $node->children = self::wrappedCell($s, $node, $ctx, self::mode($node->type, $hard, $caption));
                $node->lines = null;
            } elseif ($node->lines !== null) {
                $node->children = self::parse($s, $node->lines, $ctx, 0, self::mode($node->type, $hard, $caption));
                $node->lines = null;
                if ($node->children === [] && $node->type === 'line') {
                    // C2-A-158: a line with no children has no position, also when it held spaces.
                    $node->start = -1;
                    $node->end = -1;
                }
            }
            $attrs = $node->f['attrs'] ?? null;
            if ($node->type === 'div' && $attrs instanceof Attrs && in_array('hardbreaks', $attrs->classes, true)) {
                $hard = true;
            }
            foreach ($node->children ?? [] as $child) {
                $stack[] = [$child, $hard, $node->type === 'caption'];
            }
            $caption = $node->f['caption'] ?? null;
            if ($caption instanceof Node) {
                $stack[] = [$caption, $hard, false];
            }
            $title = $node->f['title'] ?? null;
            if ($title instanceof Node) {
                $node->f['title'] = self::title($node, $title, $s, $ctx, 0, $hard);
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
        if (($mode & Scanner::CELL) !== 0 && count($spans) > 2) {
            return self::cellFragments($s, $spans, $ctx, $depth, $mode);
        }
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

        return $nodes;
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
        // C2-I-033: one escape and one character class, linear; code fence titles only, not a hot path.
        $value = (string)preg_replace('/\\\\([!-\/:-@\[-`{-~])/', '$1', $raw);

        return [$value === $raw ? Node::text($value, $start, $end) : Node::text($value, -1, -1)];
    }

    /**
     * C2-B-184, C2-B-223: each physical fragment is one inline content, joined by text spaces.
     *
     * @param string $s
     * @param array<int> $spans
     * @param \MarkupCarve\Carve2\Inline\InlineContext $ctx
     * @param int $depth
     * @param int $mode
     * @param array<int, array{0: int, 1: int}>|null $joins Per fragment pair, the child that holds its joining space and
     *   the offset behind it.
     *
     * @return array<\MarkupCarve\Carve2\Node>
     */
    private static function cellFragments(
        string $s,
        array $spans,
        InlineContext $ctx,
        int $depth,
        int $mode,
        ?array &$joins = null,
    ): array {
        $out = [];
        $n = count($spans);
        for ($i = 0; $i < $n; $i += 2) {
            if ($spans[$i + 1] <= $spans[$i]) {
                continue;
            }
            $piece = self::parse($s, [$spans[$i], $spans[$i + 1]], $ctx, $depth, $mode);
            if ($piece === []) {
                continue;
            }
            if ($out !== []) {
                self::appendText($out, Node::text(' ', -1, -1));
                $last = $out[count($out) - 1];
                $joins[intdiv($i, 2)] = [count($out) - 1, strlen(is_string($last->f['value'] ?? null) ? $last->f['value'] : '')];
            } else {
                // C2-L-048: content that starts in a continuation row has no joining space.
                $joins[intdiv($i, 2)] = [0, 0];
            }
            foreach ($piece as $node) {
                if ($node->type === 'text') {
                    self::appendText($out, $node);
                } else {
                    $out[] = $node;
                }
            }
        }

        return $out;
    }

    /**
     * C2-L-020: a cell joined from continuation rows records where each row's fragment starts, as the child of its
     * paragraph and the offset in that child's value; LayoutBuilder writes them as the `wraps` of the cell.
     *
     * @param string $s
     * @param \MarkupCarve\Carve2\Node $node
     * @param \MarkupCarve\Carve2\Inline\InlineContext $ctx
     * @param int $mode
     *
     * @return array<\MarkupCarve\Carve2\Node>
     */
    private static function wrappedCell(string $s, Node $node, InlineContext $ctx, int $mode): array
    {
        $joins = [];
        $children = self::cellFragments($s, $node->lines ?? [], $ctx, 0, $mode, $joins);
        $at = is_array($node->layout['wrapAt'] ?? null) ? $node->layout['wrapAt'] : [];
        $wraps = [];
        foreach ($joins ?? [] as $pair => [$child, $offset]) {
            $place = $at[$pair] ?? null;
            $line = is_array($place) && is_int($place[0] ?? null) ? $place[0] : 0;
            $row = is_array($place) && is_int($place[1] ?? null) ? $place[1] : 0;
            if ($line > 0) {
                $wraps[] = ['line' => $line, 'child' => $child, 'offset' => $offset, 'row' => $row];
            }
        }
        $node->layout['cellWraps'] = $wraps;

        return $children;
    }

    /**
     * C2-A-094: adjacent text fragments are one node; a synthetic join has no source position.
     *
     * @param array<\MarkupCarve\Carve2\Node> $out
     * @param \MarkupCarve\Carve2\Node $node
     *
     * @return void
     */
    private static function appendText(array &$out, Node $node): void
    {
        $last = $out === [] ? null : $out[count($out) - 1];
        if ($last === null || $last->type !== 'text') {
            $out[] = $node;

            return;
        }
        $left = $last->f['value'] ?? null;
        $right = $node->f['value'] ?? null;
        if (!is_string($left) || !is_string($right)) {
            $out[] = $node;

            return;
        }
        $last->f['value'] = $left . $right;
        if ($last->end >= 0 && $last->end === $node->start) {
            $last->end = $node->end;
        } else {
            $last->start = -1;
            $last->end = -1;
        }
    }
}
