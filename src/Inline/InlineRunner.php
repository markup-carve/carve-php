<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Inline;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Node;

/**
 * Runs the inline pass over every leaf the block pass left with content line spans (C2-I-001).
 */
final class InlineRunner
{
    /**
     * The scans that narrow the formed images (C2-I-074), as in carve-js.
     *
     * @var int
     */
    private const MAX_IMAGE_PASSES = 8;

    /**
     * @param \MarkupCarve\Carve\Node $doc
     * @param string $s
     * @param \MarkupCarve\Carve\Inline\InlineContext $ctx
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
     * @param \MarkupCarve\Carve\Node $doc
     * @param string $s
     * @param \MarkupCarve\Carve\Inline\InlineContext $ctx
     *
     * @return void
     */
    private static function walk(Node $doc, string $s, InlineContext $ctx): void
    {
        // Parallel stacks of nodes and their mode bits (HARD_BREAKS, CAPTION), so no array is built per node.
        $nodes = [$doc];
        $modes = [0];
        $top = 1;
        while ($top > 0) {
            $node = $nodes[--$top];
            $bits = $modes[$top];
            // The parser below finishes each new inline subtree. Only children that came from the block pass
            // can still hold source spans, so do not schedule the freshly parsed nodes for another walk.
            $blockChildren = $node->children;
            if ($node->lines !== null) {
                self::leaf($node, $s, $ctx, $bits);
            }
            $hard = $bits & Scanner::HARD_BREAKS;
            $caption = null;
            if ($node->f !== []) {
                $hard = self::slots($node, $s, $ctx, $hard);
                $caption = $node->f['caption'] ?? null;
            }
            if ($blockChildren !== null) {
                $child = $node->type === 'caption' ? $hard | Scanner::CAPTION : $hard;
                foreach ($blockChildren as $kid) {
                    $nodes[$top] = $kid;
                    $modes[$top++] = $child;
                }
            }
            if ($caption instanceof Node) {
                $nodes[$top] = $caption;
                $modes[$top++] = $hard;
            }
        }
    }

    /**
     * The inline pass of one leaf that still holds content line spans.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param string $s
     * @param \MarkupCarve\Carve\Inline\InlineContext $ctx
     * @param int $bits HARD_BREAKS and CAPTION from the walk.
     *
     * @return void
     */
    private static function leaf(Node $node, string $s, InlineContext $ctx, int $bits): void
    {
        $type = $node->type;
        $mode = $bits | ($type === 'line' ? Scanner::LINE_BLOCK : 0) | ($type === 'cell_paragraph' ? Scanner::CELL : 0);
        if (isset($node->layout['wrapAt'])) {
            $node->children = self::wrappedCell($s, $node, $ctx, $mode);
            $node->lines = null;

            return;
        }
        $node->children = self::parse($s, $node->lines ?? [], $ctx, 0, $mode);
        $node->lines = null;
        if ($type === 'paragraph' && isset($node->f['role']) && !self::holdsRole($node->children, $node->f['role'])) {
            // C2-A-136: source shape may outlive an inline pair stopped by the depth limit.
            unset($node->f['role']);
        }
        if (isset($node->layout['lazyComments'])) {
            self::appendLazyComments($node);
        }
        if ($node->children === [] && $type === 'line') {
            // C2-A-158: a line with no children has no position, also when it held spaces.
            $node->start = -1;
            $node->end = -1;
        }
    }

    /**
     * C2-A-136, C2-B-157: one node of the role, then only the breaks and comments of comment-only lines.
     *
     * @param array<\MarkupCarve\Carve\Node> $children
     * @param mixed $role
     *
     * @return bool
     */
    private static function holdsRole(array $children, mixed $role): bool
    {
        if ($children === [] || $children[0]->type !== $role) {
            return false;
        }
        foreach (array_slice($children, 1) as $child) {
            if ($child->type !== 'soft_break' && $child->type !== 'comment') {
                return false;
            }
        }

        return true;
    }

    /**
     * The hardbreaks class of a div and the title slot. Returns the hard-break bit for the children.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param string $s
     * @param \MarkupCarve\Carve\Inline\InlineContext $ctx
     * @param int $hard
     *
     * @return int
     */
    private static function slots(Node $node, string $s, InlineContext $ctx, int $hard): int
    {
        $attrs = $node->f['attrs'] ?? null;
        if ($node->type === 'div' && $attrs instanceof Attrs && in_array('hardbreaks', $attrs->classes, true)) {
            $hard = Scanner::HARD_BREAKS;
        }
        $title = $node->f['title'] ?? null;
        if ($title instanceof Node) {
            $node->f['title'] = self::title($node, $title, $s, $ctx, 0, $hard !== 0);
        }

        return $hard;
    }

    private static function appendLazyComments(Node $node): void
    {
        $comments = $node->layout['lazyComments'] ?? null;
        if (!is_array($comments)) {
            return;
        }
        foreach ($comments as $part) {
            if (
                !is_array($part) || !is_int($part[0] ?? null) || !is_int($part[1] ?? null)
                || !is_string($part[2] ?? null)
            ) {
                continue;
            }
            $comment = Node::at('comment', $part[0], $part[1]);
            $comment->f['form'] = 'line';
            $comment->f['value'] = $part[2];
            $node->children[] = $comment;
        }
    }

    /**
     * C2-A-024, ast-schema.json: the title slot is an inline list; the carrier node of the block pass goes.
     *
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Node $title
     * @param string $s
     * @param \MarkupCarve\Carve\Inline\InlineContext $ctx
     * @param int $depth
     * @param bool $hard
     *
     * @return array<\MarkupCarve\Carve\Node>
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
     * @param \MarkupCarve\Carve\Inline\InlineContext $ctx
     * @param int $depth
     * @param int $mode Bits of the Scanner mode constants.
     *
     * @return array<\MarkupCarve\Carve\Node>
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
        if (count($spans) === 2 && $ctx->abbreviations === []) {
            $start = $spans[0];
            $length = max(0, $spans[1] - $start);
            if (strcspn($s, Scanner::SPECIALS, $start, $length) === $length) {
                $value = substr($s, $start, $length);
                $last = $value === '' ? ' ' : $value[-1];
                if ($last === ' ' || $last === "\t") {
                    $value = Content::trimEnd($value);
                }
                if ($value === '') {
                    return [];
                }
                if (strcspn($value, Tables::SMART_TRIGGERS) === strlen($value) || !Finisher::replaces($value)) {
                    return [Node::text($value, $start, $start + strlen($value))];
                }
            }
        }
        if ($depth === 0 && count($spans) > 2 && $ctx->abbreviations === []) {
            $plain = self::plainLines($s, $spans, $mode);
            if ($plain !== null) {
                return $plain;
            }
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
        [$scanner, $tokens] = self::scan($content, $ctx, $depth, $mode);
        $items = Delimiters::run($scanner, $tokens, $depth);

        $nodes = (new Finisher($scanner, $ctx))->build($items, -1, true);

        return $nodes;
    }

    /**
     * C2-I-074: a `%%` stays alt source only inside an image that forms. Each further scan keeps the images that formed
     * again, until the set holds.
     *
     * @param \MarkupCarve\Carve\Inline\Content $content
     * @param \MarkupCarve\Carve\Inline\InlineContext $ctx
     * @param int $depth
     * @param int $mode
     *
     * @return array{\MarkupCarve\Carve\Inline\Scanner, array<int, array<mixed>>}
     */
    private static function scan(Content $content, InlineContext $ctx, int $depth, int $mode): array
    {
        $scanner = new Scanner($content, $ctx, $depth, $mode);
        $tokens = $scanner->scan();
        $plan = null;
        if ($scanner->noteUnclosed) {
            // C2-I-089: behind the first `^[` left open the scan read every caret pair as ordinary, so it holds the
            // note-reading close of each `^[` there; one scan with every caret pair open adds where each would close.
            $notes = $scanner;
            if ($scanner->capHit) {
                // Behind an unclosed `^[` the cap counted frames the final scan does not open.
                $notes = (new Scanner($content, $ctx, $depth, $mode))->withUncapped();
                $notes->scan();
            }
            $open = (new Scanner($content, $ctx, $depth, $mode))->withCaretsOpen();
            $open->scan();
            $plan = [$notes->caretCloses, $open->caretCloses];
            $scanner = (new Scanner($content, $ctx, $depth, $mode))->withNotePlan(...$plan);
            $tokens = $scanner->scan();
        }
        if (!$scanner->percentInImage || !$scanner->percentOutsideFormed()) {
            return [$scanner, $tokens];
        }
        $formed = null;
        for ($pass = 0; $pass < self::MAX_IMAGE_PASSES; $pass++) {
            $next = $formed === null ? $scanner->formed : array_intersect_key($formed, $scanner->formed);
            if ($formed !== null && count($next) === count($formed)) {
                break;
            }
            $formed = $next;
            $scanner = new Scanner($content, $ctx, $depth, $mode, $formed);
            if ($plan !== null) {
                $scanner->withNotePlan(...$plan);
            }
            $tokens = $scanner->scan();
        }

        return [$scanner, $tokens];
    }

    /**
     * Lines with no special character and nothing to replace: the nodes the scanner and finisher make, without them.
     *
     * @param string $s
     * @param array<int> $spans
     * @param int $mode
     *
     * @return array<\MarkupCarve\Carve\Node>|null Null when a line needs the scanner.
     */
    private static function plainLines(string $s, array $spans, int $mode): ?array
    {
        $type = ($mode & Scanner::HARD_BREAKS) !== 0 ? 'hard_break' : 'soft_break';
        $out = [];
        $end = -1;
        $n = count($spans);
        for ($k = 0; $k + 1 < $n; $k += 2) {
            $start = $spans[$k];
            $length = max(0, $spans[$k + 1] - $start);
            if (strcspn($s, Scanner::SPECIALS, $start, $length) !== $length) {
                return null;
            }
            $value = rtrim(substr($s, $start, $length), " \t");
            if ($k > 0) {
                // The break takes the spaces that open the next line (Scanner::lineBreak).
                $lead = strspn($value, " \t");
                $start += $lead;
                $value = substr($value, $lead);
            }
            if ($value === '' || Finisher::replaces($value)) {
                return null;
            }
            if ($k > 0) {
                $out[] = Node::at($type, $end, $start);
            }
            $end = $start + strlen($value);
            $out[] = Node::text($value, $start, $end);
        }

        return $out;
    }

    /**
     * G-inline-6: the quoted title of a code fence is one text, its escapes read as in a quoted value
     * (C2-I-033); a text that lost a backslash has no position (C2-A-158).
     *
     * @param string $s
     * @param array<int> $spans
     *
     * @return array<\MarkupCarve\Carve\Node>
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
     * @param \MarkupCarve\Carve\Inline\InlineContext $ctx
     * @param int $depth
     * @param int $mode
     * @param array<int, array{0: int, 1: int}>|null $joins Per fragment pair, the child that holds its joining space and
     *   the offset behind it.
     *
     * @return array<\MarkupCarve\Carve\Node>
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
     * @param \MarkupCarve\Carve\Node $node
     * @param \MarkupCarve\Carve\Inline\InlineContext $ctx
     * @param int $mode
     *
     * @return array<\MarkupCarve\Carve\Node>
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
     * @param array<\MarkupCarve\Carve\Node> $out
     * @param \MarkupCarve\Carve\Node $node
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
