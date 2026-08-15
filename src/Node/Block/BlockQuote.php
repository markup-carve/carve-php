<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * Block quote
 */
class BlockQuote extends BlockNode
{
    public function getType(): string
    {
        return 'block_quote';
    }
}
