<?php

declare(strict_types=1);

namespace Carve\Node\Inline;

/**
 * Anonymous inline footnote ^[content]
 */
class InlineFootnote extends InlineNode
{
    public function getType(): string
    {
        return 'inline_footnote';
    }
}
