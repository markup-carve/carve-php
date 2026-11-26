<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use stdClass;

/**
 * One block as the data rules read it; declared fields for the same reason as {@see LineRecord}. Fields made on first
 * read that may be null (`layout`) stay dynamic, so `property_exists()` still tells whether they were made.
 */
final class BlockRecord extends stdClass
{
    /**
     * @var array<string>|null The ancestor types, made on first read; null until then.
     */
    public ?array $ancestors = null;

    /**
     * @var array<string>|null The ancestor paths, made on first read.
     */
    public ?array $ancestor_ids = null;

    public mixed $source_format = null;

    public mixed $group = null;

    public mixed $row_index = null;

    public mixed $node = null;

    public mixed $nodeSource = null;

    public function __construct(
        public mixed $type,
        public mixed $path,
        public mixed $identity,
        public mixed $start,
        public mixed $end,
        public mixed $text,
        public mixed $line,
        public mixed $last_line,
        public mixed $column,
        public mixed $opener_column,
        public mixed $mark_column,
        public mixed $quote_depth_internal,
        public mixed $opener_start,
        public mixed $opener_text,
        public mixed $owner,
        public mixed $item_owner,
        public mixed $parent,
        public mixed $index,
        public mixed $attributed,
        public mixed $image_line,
        public mixed $after_quote_mark,
        public mixed $prev_type,
    ) {
    }
}
