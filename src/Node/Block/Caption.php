<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * Caption block for images, tables, and blockquotes.
 *
 * In Carve syntax: `^ Caption text`
 *
 * The caption applies to the immediately preceding block (image, table, or blockquote).
 */
class Caption extends BlockNode
{
    public function getType(): string
    {
        return 'caption';
    }
}
