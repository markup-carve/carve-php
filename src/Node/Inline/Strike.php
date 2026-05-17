<?php

declare(strict_types=1);

namespace Carve\Node\Inline;

/**
 * Struck-through text (Carve: ~text~)
 */
class Strike extends InlineNode
{
    public function getType(): string
    {
        return 'strike';
    }
}
