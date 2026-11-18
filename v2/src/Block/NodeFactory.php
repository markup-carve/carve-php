<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Block;

use MarkupCarve\Carve2\Attrs;
use MarkupCarve\Carve2\Node;

/**
 * Makes the tree node of an open or closed block frame.
 */
final class NodeFactory
{
    /**
     * @param \MarkupCarve\Carve2\Block\Frame $f
     * @param string $s
     *
     * @return \MarkupCarve\Carve2\Node
     */
    public static function make(Frame $f, string $s): Node
    {
        if ($f->kind === Frame::ITEM && $f->attrs === null && $f->task === null) {
            $node = new Node('list_item', []);
            $node->start = $f->start;
            $node->end = $f->end;

            return $node;
        }
        if ($f->kind === Frame::PARAGRAPH && $f->attrs === null && $f->role === null) {
            $node = new Node('paragraph');
            $node->start = $f->start;
            $node->end = $f->end;
            $node->lines = $f->spans;

            return $node;
        }
        $node = self::build($f, $s);
        if (
            $f->attrs !== null && $f->kind !== Frame::DIV && $f->kind !== Frame::TABLE && $f->kind !== Frame::CODE
            && $f->kind !== Frame::LIST
        ) {
            $f->attrs->applyTo($node);
        }

        return $node;
    }

    /**
     * @param \MarkupCarve\Carve2\Block\Frame $f
     * @param string $s
     *
     * @return \MarkupCarve\Carve2\Node
     */
    private static function build(Frame $f, string $s): Node
    {
        switch ($f->kind) {
            case Frame::DOCUMENT:
                $node = new Node('document', []);
                $node->start = 0;

                return $node;
            case Frame::QUOTE:
                $node = self::container('block_quote', $f);
                // C2-L-017
                $node->layout['fenced'] = false;

                return $node;
            case Frame::LIST:
                return self::listNode($f);
            case Frame::ITEM:
                $node = self::container('list_item', $f);
                if ($f->task !== null) {
                    $node->f['task'] = ['mark' => $f->task];
                }

                return $node;
            case Frame::DLIST:
                return self::container('definition_list', $f);
            case Frame::DESC:
                return self::container('definition_description', $f);
            case Frame::FOOTNOTE:
                $node = self::container('footnote', $f);
                $node->f['key'] = self::v($f->value);
                $node->f['namespace'] = 'footnote';

                return $node;
            case Frame::DIV:
                return self::div($f);
        }

        return self::leaf($f, $s);
    }

    /**
     * @param \MarkupCarve\Carve2\Block\Frame $f
     * @param string $s
     *
     * @return \MarkupCarve\Carve2\Node
     */
    private static function leaf(Frame $f, string $s): Node
    {
        switch ($f->kind) {
            case Frame::PARAGRAPH:
            case Frame::ATTR_PARAGRAPH:
                $node = Node::at('paragraph', $f->start, $f->end);
                $node->lines = $f->spans;
                if ($f->role !== null) {
                    $node->f['role'] = $f->role;
                }

                return $node;
            case Frame::HEADING:
                $node = Node::at('heading', $f->start, $f->end);
                $node->f['level'] = $f->level;
                $node->lines = $f->spans;

                return $node;
            case Frame::TERM:
                $node = Node::at('definition_term', $f->start, $f->end);
                $node->lines = $f->spans;

                return $node;
            case Frame::CODE:
                return self::code($f, $s);
            case Frame::COMMENT_BLOCK:
                return self::commentBlock($f, $s);
            case Frame::TABLE:
                return TableBuilder::pipe($f);
            case Frame::THEMATIC:
                return Node::at('thematic_break', $f->start, $f->end);
            case Frame::LINE_BLOCK:
                return self::lineBlock($f, $s);
            case Frame::FRONTMATTER:
                $node = Node::at('frontmatter', $f->start, $f->end);
                $node->f['format'] = $f->value;
                $node->f['value'] = self::v(self::body($f->spans, $s));

                return $node;
        }

        return self::oneLine($f);
    }

    /**
     * @param \MarkupCarve\Carve2\Block\Frame $f
     *
     * @return \MarkupCarve\Carve2\Node
     */
    private static function oneLine(Frame $f): Node
    {
        switch ($f->kind) {
            case Frame::LINK_DEF:
                $node = Node::at('link_reference_definition', $f->start, $f->end);
                $node->f['key'] = self::v($f->value);
                $node->f['namespace'] = 'link';
                $node->f['href'] = self::v($f->href);
                if ($f->tooltip !== null) {
                    $node->f['tooltip'] = self::v($f->tooltip);
                }

                return $node;
            case Frame::ABBR_DEF:
                $node = Node::at('abbreviation_def', $f->start, $f->end);
                $node->f['key'] = $f->value;
                $node->f['namespace'] = 'abbr';
                $node->f['expansion'] = self::v($f->href);

                return $node;
            case Frame::INCLUDE:
                $node = Node::at('include', $f->start, $f->end);
                foreach ($f->include as $key => $value) {
                    if ($key === "\0spelling") {
                        $node->layout['optionSpelling'] = $value;

                        continue;
                    }
                    $node->f[$key] = is_string($value) ? self::v($value) : $value;
                }

                return $node;
            case Frame::COMMENT_LINE:
                $node = Node::at('comment', $f->start, $f->end);
                $node->f['form'] = 'line';
                $node->f['value'] = self::v($f->value);

                return $node;
        }

        return Node::at('paragraph', $f->start, $f->end);
    }

    /**
     * C2-B-218: a U+0000 is read as U+FFFD in every value.
     *
     * @param string $v
     *
     * @return string
     */
    public static function v(string $v): string
    {
        return strpos($v, "\0") === false ? $v : str_replace("\0", "\u{FFFD}", $v);
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
        $node->end = $f->end;

        return $node;
    }

    /**
     * C2-B-045, C2-B-219: the `start` attribute of an ordered list.
     *
     * @param \MarkupCarve\Carve2\Block\Frame $f
     *
     * @return \MarkupCarve\Carve2\Node
     */
    private static function listNode(Frame $f): Node
    {
        $node = self::container('list', $f);
        $m = $f->marker;
        assert($m !== null);
        if (!$m->ordered) {
            $node->f['marker'] = ['kind' => 'bullet', 'char' => $m->char];
            $f->attrs?->applyTo($node);

            return $node;
        }
        $start = $m->value < 0 ? 1 : $m->value;
        $attrs = $f->attrs;
        $written = $attrs?->get('start');
        if (
            $attrs !== null && $written !== null && $written !== '' && strlen($written) <= 9 && ctype_digit($written)
            && ($m->style === '1' || (int)$written >= 1)
        ) {
            $start = (int)$written;
            $attrs->remove('start');
        }
        $marker = ['kind' => 'ordered', 'style' => $m->style, 'delimiter' => $m->delimiter];
        // C2-L-016: the form of the first marker.
        $node->layout['bare'] = $m->value < 0;
        if ($start !== 1) {
            $marker['start'] = $start;
        }
        $node->f['marker'] = $marker;
        $attrs?->applyTo($node);

        return $node;
    }

    /**
     * C2-B-127, C2-B-128: the node type and the classes of a div.
     *
     * @param \MarkupCarve\Carve2\Block\Frame $f
     *
     * @return \MarkupCarve\Carve2\Node
     */
    private static function div(Frame $f): Node
    {
        $node = self::container($f->node, $f);
        if ($f->divKind !== '') {
            $node->f['kind'] = $f->divKind;
        }
        self::titleField($node, $f);
        $attrs = new Attrs();
        if ($f->nameClass !== '') {
            $node->sourceName = $f->nameClass;
            $attrs->classes[] = $f->nameClass;
        }
        if ($f->attrs !== null) {
            $attrs->merge($f->attrs);
        }
        if ($f->sigilClass !== '') {
            $attrs->classes[] = $f->sigilClass;
        }
        $attrs->applyTo($node);
        // C2-L-017, C2-L-018
        if ($f->node === 'block_quote') {
            $node->layout['fenced'] = true;
        } elseif (in_array('hardbreaks', $attrs->classes, true)) {
            $node->layout['hardBreakOpener'] = $f->sigilClass === 'hardbreaks';
        }

        return $node;
    }

    /**
     * The quoted title (an inline list, left as line spans for the inline pass) and the bracket label.
     *
     * @param \MarkupCarve\Carve2\Node $node
     * @param \MarkupCarve\Carve2\Block\Frame $f
     *
     * @return void
     */
    private static function titleField(Node $node, Frame $f): void
    {
        if ($f->title !== null) {
            [$a, $b] = $f->title;
            $title = Node::at('title', $a, $b);
            $title->lines = [$a, $b];
            $node->f['title'] = $title;
        }
        if ($f->label !== null) {
            $node->f['groupLabel'] = self::v($f->label);
        }
    }

    /**
     * Lines joined, each followed by a line feed (C2-B-222, C2-A-045, C2-A-049).
     *
     * @param array<int> $spans
     * @param string $s
     *
     * @return string
     */
    private static function body(array $spans, string $s): string
    {
        $parts = [];
        $n = count($spans);
        for ($k = 0; $k < $n; $k += 2) {
            $parts[] = substr($s, $spans[$k], $spans[$k + 1] - $spans[$k]);
        }

        return $parts === [] ? '' : implode("\n", $parts) . "\n";
    }

    /**
     * @param \MarkupCarve\Carve2\Block\Frame $f
     * @param string $s
     *
     * @return \MarkupCarve\Carve2\Node
     */
    private static function code(Frame $f, string $s): Node
    {
        $value = self::body($f->spans, $s);
        if ($f->delimited !== '') {
            return TableBuilder::delimited($f, self::v($value), $f->delimited);
        }
        if ($f->raw !== null) {
            $node = Node::at('raw_block', $f->start, $f->end);
            $node->f['format'] = $f->raw;
            $node->f['value'] = self::v($value);
            $f->attrs?->applyTo($node);

            return $node;
        }
        $node = Node::at('code_block', $f->start, $f->end);
        $node->f['value'] = self::v($value);
        if ($f->syntax !== '') {
            $node->f['syntax'] = $f->syntax;
        }
        self::titleField($node, $f);
        $f->attrs?->applyTo($node);

        return $node;
    }

    /**
     * C2-A-074: the body with a line break between two lines and none after the last.
     *
     * @param \MarkupCarve\Carve2\Block\Frame $f
     * @param string $s
     *
     * @return \MarkupCarve\Carve2\Node
     */
    private static function commentBlock(Frame $f, string $s): Node
    {
        $node = Node::at('comment', $f->start, $f->end);
        $node->f['form'] = 'block';
        $body = self::body($f->spans, $s);
        $node->f['value'] = self::v($body === '' ? '' : substr($body, 0, -1));

        return $node;
    }

    /**
     * C2-A-069: one `line` per body line; an empty line has no children and ends a stanza.
     *
     * @param \MarkupCarve\Carve2\Block\Frame $f
     * @param string $s
     *
     * @return \MarkupCarve\Carve2\Node
     */
    private static function lineBlock(Frame $f, string $s): Node
    {
        $node = Node::at('line_block', $f->start, $f->end);
        $node->children = [];
        $comments = $f->hiddenComments;
        if ($f->lineComment !== null) {
            $comments[] = $f->lineComment;
        }
        $commentIndex = 0;
        $n = count($f->spans);
        for ($k = 0; $k < $n; $k += 2) {
            while (isset($comments[$commentIndex]) && $comments[$commentIndex]->start < $f->spans[$k]) {
                $node->children[] = self::commentBlock($comments[$commentIndex++], $s);
            }
            $line = Node::at('line', $f->spans[$k], $f->spans[$k + 1]);
            $line->lines = $f->spans[$k + 1] > $f->spans[$k] ? [$f->spans[$k], $f->spans[$k + 1]] : [];
            if (isset($f->lazyLineComments[$k])) {
                $line->layout['lazyComments'] = $f->lazyLineComments[$k];
                $last = $f->lazyLineComments[$k][count($f->lazyLineComments[$k]) - 1];
                $line->end = $last[1];
            }
            $node->children[] = $line;
        }
        while (isset($comments[$commentIndex])) {
            $node->children[] = self::commentBlock($comments[$commentIndex++], $s);
        }
        $attrs = new Attrs();
        if ($f->attrs !== null) {
            $attrs->merge($f->attrs);
        }
        $f->attrs = null;
        $attrs->applyTo($node);

        return $node;
    }
}
