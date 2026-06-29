<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

/**
 * Inserted text {+text+}
 */
class Insert extends InlineNode
{
    public function getType(): string
    {
        return 'insert';
    }
}
