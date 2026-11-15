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
        $node = NodeFactory::make($frame, [], $this->s);
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
        $parentNode->children[] = NodeFactory::make($frame, $frame->spans, $this->s);
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
}
