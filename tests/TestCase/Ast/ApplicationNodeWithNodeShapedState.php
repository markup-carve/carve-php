<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Node\Block\BlockNode;

/**
 * An application node whose state happens to be spelled like a node.
 */
class ApplicationNodeWithNodeShapedState extends BlockNode
{
    /**
     * No DECLARED default, because the encoder omits a field holding one - and
     * a field that never reaches the wire cannot be corrupted on the way there.
     *
     * @var array<string, string>
     */
    protected array $data;

    public function __construct()
    {
        $this->data = ['type' => 'section', 'content' => 'x'];
    }

    public function getType(): string
    {
        return 'app_node_with_node_shaped_state';
    }
}
