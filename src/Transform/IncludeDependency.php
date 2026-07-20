<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Transform;

/**
 * Include target touched during expansion.
 */
class IncludeDependency
{
    public function __construct(
        protected string $target,
        protected bool $resolved,
    ) {
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }
}
