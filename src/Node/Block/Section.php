<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * Section block - wraps headings and their content
 */
class Section extends BlockNode
{
    public function getType(): string
    {
        return 'section';
    }
}
