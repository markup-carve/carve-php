<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Block;

use MarkupCarve\Carve2\Node;

/**
 * The direct shape: the block pass builds the tree nodes as it goes.
 */
final class TreeSink implements Sink
{
    public ?Node $root = null;

    /**
     * @param string $s
     */
    public function __construct(private string $s)
    {
    }

    /**
     * @inheritDoc
     */
    public function enter(Frame $frame, Frame $parent): void
    {
        $node = NodeFactory::make($frame, $this->s);
        $frame->handle = $node;
        if ($frame === $parent) {
            $this->root = $node;

            return;
        }
        $parentNode = $parent->handle;
        assert($parentNode instanceof Node);
        $parentNode->children[] = $node;
    }

    /**
     * @inheritDoc
     */
    public function leaf(Frame $frame, Frame $parent): void
    {
        $parentNode = $parent->handle;
        assert($parentNode instanceof Node);
        $node = NodeFactory::make($frame, $this->s);
        $frame->handle = $node;
        $parentNode->children[] = $node;
    }

    /**
     * @inheritDoc
     */
    public function exit(Frame $frame): void
    {
        $node = $frame->handle;
        assert($node instanceof Node);
        NodeFactory::finish($node, $frame);
    }

    /**
     * @inheritDoc
     */
    public function caption(Frame $caption, Frame $parent, Frame $target): void
    {
        $parentNode = $parent->handle;
        $node = $target->handle;
        assert($parentNode instanceof Node && $node instanceof Node && $parentNode->children !== null);
        $children = $parentNode->children;
        $at = count($children) - 1;
        while ($at >= 0 && $children[$at] !== $node) {
            $at--;
        }
        if ($at < 0) {
            return;
        }
        $after = array_slice($children, $at + 1);
        $cap = Node::at('caption', $caption->start, $caption->end);
        $para = Node::at('paragraph', $caption->spans[0], $caption->end);
        $para->lines = $caption->spans;
        $cap->children = [$para];
        if ($node->type === 'table' || $node->type === 'figure_group') {
            // C2-A-082: a comment between the block and its caption is the block's last child.
            foreach ($after as $comment) {
                $node->children[] = $comment;
            }
            $node->f['caption'] = $cap;
            $node->end = $caption->end;
            $parentNode->children = array_slice($children, 0, $at + 1);

            return;
        }
        $figure = Node::at('figure', $node->start, $caption->end);
        $figure->children = array_merge([$node], $after);
        $figure->f['caption'] = $cap;
        // C2-A-183: the attributes of a wrapped block are those of the figure.
        if (isset($node->f['attrs'])) {
            $figure->f['attrs'] = $node->f['attrs'];
            unset($node->f['attrs']);
        }
        if (isset($node->f['lang'])) {
            $figure->f['lang'] = $node->f['lang'];
            unset($node->f['lang']);
        }
        if ($target->own >= 0) {
            $node->start = $target->own;
        }
        $parentNode->children = array_slice($children, 0, $at);
        $parentNode->children[] = $figure;
    }
}
