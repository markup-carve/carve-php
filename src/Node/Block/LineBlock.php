<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * Line block - preserves line breaks
 *
 * Syntax:
 * | Line one
 * | Line two
 * | Line three
 */
class LineBlock extends BlockNode
{
    public function getType(): string
    {
        return 'line_block';
    }
}
