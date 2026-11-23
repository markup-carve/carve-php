<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use stdClass;

/**
 * One source line as the data rules read it. Declared fields keep a large document's records far smaller than a
 * dynamic property table; fields made on first read (`ancestors`) stay dynamic, so `property_exists()` still tells
 * whether they were made.
 */
final class LineRecord extends stdClass
{
    public mixed $prev = null;

    public mixed $prev2 = null;

    public mixed $next = null;

    public mixed $next2 = null;

    public function __construct(
        public mixed $number,
        public mixed $start,
        public mixed $end,
        public mixed $text,
        public mixed $body,
        public mixed $body_start,
        public mixed $body_end,
        public mixed $blank,
        public mixed $leaf,
        public mixed $leaf_id,
        public mixed $leaf_first,
        public mixed $leaf_start,
        public mixed $leaf_head,
        public mixed $leaf_next,
        public mixed $table_above,
        public mixed $table_below,
        public mixed $table_path,
        public mixed $row_path,
        public mixed $attached_here,
        public mixed $closes_container,
        public mixed $ancestor_path,
        public mixed $quote_depth,
        public mixed $quote_marks,
        public mixed $quote_prefix,
        public mixed $raw_body,
        public mixed $lazy,
        public mixed $open_colon,
        public mixed $shape,
        public mixed $path,
    ) {
    }
}
