<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

/**
 * Superscript text^super^
 */
class Superscript extends InlineNode
{
    public function getType(): string
    {
        return 'superscript';
    }
}
