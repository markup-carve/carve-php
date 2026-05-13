<?php

declare(strict_types=1);

namespace Carve\Node\Inline;

/**
 * Strong emphasis (asterisk delimited: *text*)
 */
class Strong extends InlineNode
{
    public function getType(): string
    {
        return 'strong';
    }
}
