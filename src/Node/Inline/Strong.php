<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

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
