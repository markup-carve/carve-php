<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Transform;

/**
 * Context passed to host include resolvers.
 */
class IncludeContext
{
    /**
     * @param string|null $includingPath
     * @param string|null $currentPath
     * @param list<string> $stack
     * @param int $depth
     */
    public function __construct(
        protected ?string $includingPath = null,
        protected ?string $currentPath = null,
        protected array $stack = [],
        protected int $depth = 0,
    ) {
    }

    public function getIncludingPath(): ?string
    {
        return $this->includingPath;
    }

    public function getCurrentPath(): ?string
    {
        return $this->currentPath;
    }

    /**
     * @return list<string>
     */
    public function getStack(): array
    {
        return $this->stack;
    }

    public function getDepth(): int
    {
        return $this->depth;
    }
}
