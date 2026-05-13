<?php

declare(strict_types=1);

namespace Carve\Node\Inline;

/**
 * Subscript text~sub~
 */
class Subscript extends InlineNode
{
    public function getType(): string
    {
        return 'subscript';
    }
}
