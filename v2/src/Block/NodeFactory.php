<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Block;

use MarkupCarve\Carve2\Node;

/**
 * Makes the tree node of an open or closed block. Both block pass shapes share it.
 */
final class NodeFactory
{
    /**
     * @param \MarkupCarve\Carve2\Block\Frame $f
     * @param array<int> $spans
     * @param string $s
     *
     * @return \MarkupCarve\Carve2\Node
     */
    public static function make(Frame $f, array $spans, string $s): Node
    {
        switch ($f->kind) {
            case Frame::DOCUMENT:
                $node = new Node('document', []);
                $node->start = 0;

                return $node;
            case Frame::QUOTE:
                return self::container('block_quote', $f);
            case Frame::LIST:
                $node = self::container('list', $f);
                $m = $f->data;
                $node->f['marker'] = $m['ordered'] ? self::ordered($m) : ['kind' => 'bullet', 'char' => $m['char']];

                return $node;
            case Frame::ITEM:
                return self::container('list_item', $f);
            case Frame::PARAGRAPH:
                $node = Node::at('paragraph', $f->start, $f->end);
                $node->lines = $spans;

                return $node;
            case Frame::HEADING:
                $node = Node::at('heading', $f->start, $f->end);
                $node->f['level'] = $f->level;
                $node->lines = $spans;

                return $node;
            case Frame::CODE:
                return self::code($f, $spans, $s);
            case Frame::THEMATIC:
                return Node::at('thematic_break', $f->start, $f->end);
            case Frame::TABLE:
                return self::table($f, $spans);
        }

        return Node::at('paragraph', $f->start, $f->end);
    }

    /**
     * Sets the facts known when a container closes (C2-B-191).
     *
     * @param \MarkupCarve\Carve2\Node $node
     * @param \MarkupCarve\Carve2\Block\Frame $f
     *
     * @return void
     */
    public static function finish(Node $node, Frame $f): void
    {
        $node->end = $f->end;
        if ($f->kind === Frame::LIST) {
            $node->f['spacing'] = $f->loose ? 'loose' : 'tight';
        }
    }

    /**
     * @param string $type
     * @param \MarkupCarve\Carve2\Block\Frame $f
     *
     * @return \MarkupCarve\Carve2\Node
     */
    private static function container(string $type, Frame $f): Node
    {
        $node = new Node($type, []);
        $node->start = $f->start;

        return $node;
    }

    /**
     * @param array<string, mixed> $m
     *
     * @return array<string, mixed>
     */
    private static function ordered(array $m): array
    {
        $marker = ['kind' => 'ordered', 'style' => $m['style'], 'delimiter' => $m['delimiter']];
        $start = $m['value'] < 0 ? 1 : $m['value'];
        if ($start !== 1) {
            $marker['start'] = $start;
        }

        return $marker;
    }

    /**
     * C2-B-222, C2-A-045: body lines, one line feed after each.
     *
     * @param \MarkupCarve\Carve2\Block\Frame $f
     * @param array<int> $spans
     * @param string $s
     *
     * @return \MarkupCarve\Carve2\Node
     */
    private static function code(Frame $f, array $spans, string $s): Node
    {
        $node = Node::at('code_block', $f->start, $f->end);
        $value = '';
        $n = count($spans);
        if (empty($f->data['closed'])) {
            // C2-B-113: trailing blank lines of an unclosed fence are not body.
            while ($n >= 2 && trim(substr($s, $spans[$n - 2], $spans[$n - 1] - $spans[$n - 2]), " \t") === '') {
                $n -= 2;
            }
        }
        for ($k = 0; $k < $n; $k += 2) {
            $value .= substr($s, $spans[$k], $spans[$k + 1] - $spans[$k]) . "\n";
        }
        $node->f['value'] = $value;
        $info = (string)($f->data['info'] ?? '');
        if ($info !== '') {
            $word = strtok($info, " \t");
            if ($word !== false) {
                $node->f['syntax'] = $word;
            }
        }

        return $node;
    }

    /**
     * @param \MarkupCarve\Carve2\Block\Frame $f
     * @param array<int> $spans Flat cell spans, -1 between rows.
     *
     * @return \MarkupCarve\Carve2\Node
     */
    private static function table(Frame $f, array $spans): Node
    {
        $table = Node::at('table', $f->start, $f->end);
        $body = new Node('table_body', []);
        $row = null;
        $n = count($spans);
        for ($k = 0; $k < $n; $k++) {
            if ($spans[$k] === -1) {
                $row = new Node('table_row', []);
                $body->children[] = $row;

                continue;
            }
            assert($row !== null);
            $cell = Node::at('table_cell', $spans[$k], $spans[$k + 1]);
            $cell->f['header'] = false;
            $para = Node::at('cell_paragraph', $spans[$k], $spans[$k + 1]);
            $para->lines = [$spans[$k], $spans[$k + 1]];
            $cell->children = [$para];
            $row->children[] = $cell;
            $k++;
        }
        $table->f['columns'] = [];
        $table->children = [$body];

        return $table;
    }
}
