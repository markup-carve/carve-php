<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use stdClass;

/**
 * The source span of a block no rule reads, kept for line ownership and colon containers.
 */
final class BlockBounds extends stdClass
{
    public function __construct(
        public mixed $type,
        public mixed $path,
        public mixed $start,
        public mixed $end,
        public mixed $opener_start,
        public mixed $opener_text = null,
        public mixed $line = null,
        public mixed $last_line = null,
    ) {
    }
}
