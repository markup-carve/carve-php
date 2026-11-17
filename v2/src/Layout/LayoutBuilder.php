<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Layout;

use MarkupCarve\Carve2\Node;

/**
 * Builds the source-layout record (source-layout.md) of a parsed tree: the ranges, the blank lines of C2-L-047 and the
 * facts the parser recorded on its nodes. The measured facts of the section "Measured facts carried over" other than
 * the blank lines are not measured, which C2-L-014 allows: an absent fact means unknown.
 */
final class LayoutBuilder
{
    /**
     * C2-L-047: the types that exist only inside a container and still have the blank-line facts.
     *
     * @var array<string, true>
     */
    private const OWN_BLANKS = [
        'list_item' => true,
        'definition_term' => true,
        'definition_description' => true,
        'caption' => true,
    ];

    /**
     * The types whose `children` are a list of blocks (C2-A-051); a caption is read apart, its blocks have no facts.
     *
     * @var array<string, true>
     */
    private const BLOCK_PARENTS = [
        'document' => true,
        'block_quote' => true,
        'list_item' => true,
        'definition_description' => true,
        'footnote' => true,
        'div' => true,
        'admonition' => true,
        'directive' => true,
        'figure_group' => true,
        'section' => true,
        'block_extension' => true,
        'table_cell' => true,
    ];

    /**
     * @var array<array<string, mixed>>
     */
    private array $entries = [];

    private function __construct(private SourceLines $lines)
    {
    }

    /**
     * @param string $source The input as given: its bytes.
     * @param \MarkupCarve\Carve2\Node $root
     *
     * @return array<string, mixed>
     */
    public static function build(string $source, Node $root): array
    {
        $builder = new self(new SourceLines($source));
        $builder->walk($root, '', null, 0, false);
        $utf8 = mb_check_encoding($source, 'UTF-8');

        return [
            'version' => 2,
            // C2-L-056
            'encoding' => $utf8 ? 'utf-8' : 'base64',
            'source' => $utf8 ? $source : base64_encode($source),
            'lineEndings' => $builder->lines->lineEndings(),
            'bom' => str_starts_with($source, "\u{FEFF}"),
            'nodes' => $builder->entries,
        ];
    }

    /**
     * C2-L-011: a node before the nodes inside it, the slots in the order title, children, caption, then the others.
     *
     * @param \MarkupCarve\Carve2\Node $node
     * @param string $path
     * @param array{0: int, 1: int}|null $blanks The floor and the limit of the blank lines around a block, or null.
     * @param int $quotes The open quotes of `>` lines.
     * @param bool $inCaption
     *
     * @return void
     */
    private function walk(Node $node, string $path, ?array $blanks, int $quotes, bool $inCaption): void
    {
        $this->entry($node, $path, $blanks, $quotes);
        if ($node->type === 'block_quote' && ($node->layout['fenced'] ?? null) === false) {
            $quotes++;
        }
        $inner = $inCaption || $node->type === 'caption';
        $title = $node->f['title'] ?? null;
        if (is_array($title)) {
            $this->list($title, $path . '/title', $node, $quotes, false, $inner);
        }
        // C2-N-082: the blocks of an expanded include have no entries.
        if ($node->children !== null && $node->type !== 'include') {
            $blocks = !$inner && isset(self::BLOCK_PARENTS[$node->type]);
            $this->list($node->children, $path . '/children', $node, $quotes, $blocks, $inner);
        }
        $caption = $node->f['caption'] ?? null;
        if ($caption instanceof Node) {
            $around = $inCaption ? null : [max(0, $node->start), $blanks[1] ?? strlen($this->lines->s)];
            $this->walk($caption, $path . '/caption', $around, $quotes, true);
        }
        $short = $node->f['short'] ?? null;
        if (is_array($short)) {
            $this->list($short, $path . '/short', $node, $quotes, false, true);
        }
        $slots = $node->f['slots'] ?? null;
        if (is_array($slots)) {
            $this->slots($slots, $path, $node, $quotes, $inner);
        }
    }

    /**
     * C2-X-020: the named slots in the order of the declaration, which the transform keeps.
     *
     * @param array<mixed> $slots
     * @param string $path
     * @param \MarkupCarve\Carve2\Node $node
     * @param int $quotes
     * @param bool $inner
     *
     * @return void
     */
    private function slots(array $slots, string $path, Node $node, int $quotes, bool $inner): void
    {
        foreach ($slots as $name => $list) {
            if (is_array($list)) {
                // C2-X-041: the `title` slot of a tab holds inline nodes, the other standard slots blocks.
                $blocks = !$inner && $node->type === 'block_extension' && $name !== 'title';
                $this->list($list, $path . '/slots/' . self::escape((string)$name), $node, $quotes, $blocks, $inner);
            }
        }
    }

    /**
     * @param array<mixed> $list
     * @param string $path
     * @param \MarkupCarve\Carve2\Node $parent
     * @param int $quotes
     * @param bool $blocks The nodes are a list of blocks outside a caption.
     * @param bool $inCaption
     *
     * @return void
     */
    private function list(array $list, string $path, Node $parent, int $quotes, bool $blocks, bool $inCaption): void
    {
        $limit = $parent->end >= 0 && $parent->type !== 'document' ? $parent->end : strlen($this->lines->s);
        $previousEnd = max(0, $parent->start);
        $list = array_values($list);
        foreach ($list as $i => $child) {
            if (!$child instanceof Node) {
                continue;
            }
            $next = $list[$i + 1] ?? null;
            $nextStart = $next instanceof Node && $next->start >= 0 ? $next->start : $limit;
            $own = $blocks || (!$inCaption && isset(self::OWN_BLANKS[$child->type]));
            $this->walk($child, $path . '/' . $i, $own ? [$previousEnd, $nextStart] : null, $quotes, $inCaption);
            if ($child->end >= 0) {
                $previousEnd = $child->end;
            }
        }
    }

    /**
     * @param \MarkupCarve\Carve2\Node $node
     * @param string $path
     * @param array{0: int, 1: int}|null $blanks
     * @param int $quotes
     *
     * @return void
     */
    private function entry(Node $node, string $path, ?array $blanks, int $quotes): void
    {
        $start = $node->start;
        $end = $node->end;
        $facts = $node->layout;
        if (is_int($facts['startByte'] ?? null) && is_int($facts['endByte'] ?? null)) {
            $start = $facts['startByte'];
            $end = $facts['endByte'];
        }
        if (is_int($facts['startByte'] ?? null) && is_int($facts['contentEnd'] ?? null)) {
            $start = $facts['startByte'];
            $end = $facts['contentEnd'] + strspn($this->lines->s, " \t", $facts['contentEnd']);
        }
        unset($facts['startByte'], $facts['endByte'], $facts['contentEnd']);
        if (is_int($facts['refEnd'] ?? null)) {
            $facts = ['rawRef' => $this->rawRef($node, $facts['refEnd'], $quotes)] + $facts;
        }
        unset($facts['refEnd'], $facts['wrapAt'], $facts['spansRows'], $facts['cellWraps']);
        if ($node->type === 'table_cell') {
            $facts += $this->wraps($node, $path);
        }
        if ($node->type === 'caption') {
            // C2-L-054: a parse gives every caption the attachment `preceding`.
            $facts['attachment'] = 'preceding';
        }
        if ($blanks !== null && $start >= 0) {
            $facts['blankLinesBefore'] = $this->lines->blankBefore($start, $blanks[0], $quotes);
            $facts['blankLinesAfter'] = $this->lines->blankAfter($end, $blanks[1], $quotes);
        }
        if ($start < 0 || $facts === []) {
            return;
        }
        $this->entries[] = ['path' => $path, 'startByte' => $start, 'endByte' => $end] + $facts;
    }

    /**
     * C2-L-020, C2-L-048: the wraps of a cell, sorted by line and then by row; `row` only on a cell that spans rows.
     *
     * @param \MarkupCarve\Carve2\Node $cell
     * @param string $path
     *
     * @return array<string, mixed>
     */
    private function wraps(Node $cell, string $path): array
    {
        $para = $cell->children[0] ?? null;
        $found = $para instanceof Node && is_array($para->layout['cellWraps'] ?? null) ? $para->layout['cellWraps'] : [];
        if ($found === []) {
            return [];
        }
        $rows = ($para->layout['spansRows'] ?? false) === true;
        $wraps = [];
        foreach ($found as $wrap) {
            if (!is_array($wrap) || !is_int($wrap['line'] ?? null) || !is_int($wrap['child'] ?? null)) {
                continue;
            }
            $child = $path . '/children/0/children/' . $wrap['child'];
            $entry = ['line' => $wrap['line'], 'path' => $child, 'offset' => $wrap['offset'] ?? 0];
            $wraps[] = $rows ? $entry + ['row' => $wrap['row'] ?? 0] : $entry;
        }
        usort($wraps, static fn (array $a, array $b): int => [$a['line'], $a['row'] ?? 0] <=> [$b['line'], $b['row'] ?? 0]);

        return ['wraps' => $wraps];
    }

    private function rawRef(Node $node, int $stop, int $quotes): string
    {
        // C2-L-049: from the opening bracket, without the `!` of an image.
        $start = $node->start + ($node->type === 'image' ? 1 : 0);

        return $this->lines->joined($start, $stop, $quotes);
    }

    private static function escape(string $part): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $part);
    }
}
