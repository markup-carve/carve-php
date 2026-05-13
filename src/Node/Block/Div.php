<?php

declare(strict_types=1);

namespace Carve\Node\Block;

/**
 * Generic div container (fenced with :::)
 */
class Div extends BlockNode
{
    public function getType(): string
    {
        return 'div';
    }
}
