<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * Paragraph block
 */
class Paragraph extends BlockNode
{
    public function getType(): string
    {
        return 'paragraph';
    }
}
