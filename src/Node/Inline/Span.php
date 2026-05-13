<?php

declare(strict_types=1);

namespace Carve\Node\Inline;

/**
 * Generic span with attributes [text]{.class}
 */
class Span extends InlineNode
{
    public function getType(): string
    {
        return 'span';
    }
}
