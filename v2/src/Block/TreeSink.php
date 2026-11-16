<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Block;

use MarkupCarve\Carve2\Diagnostics\Findings;
use MarkupCarve\Carve2\Node;

/**
 * The direct shape: the block pass builds the tree nodes as it goes.
 */
final class TreeSink implements Sink
{
    public ?Node $root = null;

    public Findings $findings;

    /**
     * @param string $s
     */
    public function __construct(private string $s)
    {
        $this->findings = new Findings();
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
        /** @var \MarkupCarve\Carve2\Node $parentNode */
        $parentNode = $parent->handle;
        $parentNode->children[] = $node;
    }

    /**
     * @inheritDoc
     */
    public function leaf(Frame $frame, Frame $parent): void
    {
        /** @var \MarkupCarve\Carve2\Node $parentNode */
        $parentNode = $parent->handle;
        $node = NodeFactory::make($frame, $this->s);
        $frame->handle = $node;
        $at = count($parentNode->children ?? []);
        if ($at === 0 || $parentNode->children[$at - 1]->start <= $node->start) {
            $parentNode->children[] = $node;

            return;
        }
        $children = $parentNode->children;
        while ($at > 0 && $children[$at - 1]->start > $node->start) {
            $at--;
        }
        array_splice($children, $at, 0, [$node]);
        $parentNode->children = $children;
    }

    /**
     * @inheritDoc
     */
    public function exit(Frame $frame): void
    {
        /** @var \MarkupCarve\Carve2\Node $node */
        $node = $frame->handle;
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
        if ($node->type !== 'code_block') {
            // C2-A-082: comments below an open block stay inside that block.
            foreach ($after as $comment) {
                $node->children[] = $comment;
            }
            $parentNode->children = array_slice($children, 0, $at + 1);
        }
        $node->f['caption'] = $cap;
        $node->end = $caption->end;
        $node->ownStart = $target->own;
    }
}
