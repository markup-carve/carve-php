<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

/**
 * @internal
 */
final class MarkdownDelimiterRun
{
    public int $left = 0;

    public int $right = 0;

    public bool $active = true;

    public int $previous = -1;

    public int $next = -1;

    public function __construct(
        public int $start,
        public int $end,
        public string $char,
        public bool $open,
        public bool $close,
    ) {
    }

    public function remaining(): int
    {
        return $this->end - $this->start - $this->left - $this->right;
    }
}
