<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

class NonBreakingSpace extends InlineNode
{
    public function getType(): string
    {
        return 'non_breaking_space';
    }
}
