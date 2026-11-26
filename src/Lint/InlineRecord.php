<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use stdClass;

/**
 * One inline node as the data rules read it; declared fields for the same reason as {@see LineRecord}.
 */
final class InlineRecord extends stdClass
{
    public mixed $node = null;

    public mixed $nodeSource = null;

    public function __construct(
        public mixed $type,
        public mixed $path,
        public mixed $parent,
        public mixed $leaf,
        public mixed $start,
        public mixed $end,
        public mixed $text,
    ) {
    }
}
