<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

/**
 * Underlined text (Carve: _text_)
 */
class Underline extends InlineNode
{
    public function getType(): string
    {
        return 'underline';
    }
}
