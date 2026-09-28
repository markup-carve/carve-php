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
        /**
         * Content column of the container nested inside the collected one that
         * holds this fence, or 0 when the fence is the collected container's
         * own. A line below it ends that container and the fence with it.
         */
        public readonly int $hostColumn = 0,
    ) {
    }
}
