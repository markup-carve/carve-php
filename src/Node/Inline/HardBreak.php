<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

/**
 * Hard line break (backslash + newline)
 */
class HardBreak extends InlineNode
{
    public function getType(): string
    {
        return 'hard_break';
    }
}
