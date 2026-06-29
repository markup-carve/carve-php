<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

/**
 * Critic substitution {~old~>new~}
 */
class Substitution extends InlineNode
{
    public function __construct(
        protected string $oldText = '',
        protected string $newText = '',
    ) {
    }

    public function getOldText(): string
    {
        return $this->oldText;
    }

    public function getNewText(): string
    {
        return $this->newText;
    }

    public function getType(): string
    {
        return 'substitution';
    }
}
