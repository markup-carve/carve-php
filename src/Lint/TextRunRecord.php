<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use stdClass;

/**
 * A run of text and soft breaks as the data rules read it; declared fields for the same reason as {@see LineRecord}.
 */
final class TextRunRecord extends stdClass
{
    public ?string $text = null;

    public function __construct(
        public mixed $type,
        public mixed $path,
        public mixed $parent,
        public mixed $leaf,
        public int $start,
        public int $end,
        public int $next,
    ) {
    }
}
