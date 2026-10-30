<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;

/**
 * What a line opens, as the ownership walk classifies it.
 *
 * @internal
 */
final class OwnershipForm
{
    /**
     * @param string $kind
     * @param bool $interrupts
     * @param int $frame
     * @param (\Closure(string, int): bool)|null $closes
     * @param string|null $axes
     * @param int $head
     * @param int $width
     * @param bool $lineBlock
     * @param int|null $run
     * @param string|null $family
     * @param bool $task
     * @param bool $caption
     * @param bool $table
     */
    public function __construct(
        public readonly string $kind,
        public readonly bool $interrupts,
        public readonly int $frame = 0,
        public readonly ?Closure $closes = null,
        public readonly ?int $run = null,
        public readonly bool $lineBlock = false,
        public readonly int $width = 0,
        public readonly int $head = 0,
        public readonly ?string $axes = null,
        public readonly ?string $family = null,
        public readonly bool $task = false,
        public readonly bool $caption = false,
        public readonly bool $table = false,
    ) {
    }
}
