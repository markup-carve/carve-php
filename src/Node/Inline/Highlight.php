<?php

declare(strict_types=1);

namespace Carve\Node\Inline;

/**
 * Highlighted text {=text=}
 */
class Highlight extends InlineNode
{
    public function getType(): string
    {
        return 'highlight';
    }
}
