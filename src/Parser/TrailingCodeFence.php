<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * @internal
 */
final class TrailingCodeFence
{
    public function __construct(
        public readonly string $char,
        public readonly int $length,
        public readonly int $column,
    ) {
    }
}
