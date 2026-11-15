<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Block;

use MarkupCarve\Carve2\Attrs;

/**
 * One cell of a pipe table row while the table is open (C2-B-171 to C2-B-173).
 */
final class TableCell
{
    public bool $header = false;

    public ?Attrs $attrs = null;

    public ?string $align = null;

    public string $mark = '';

    public int $cs = 0;

    public int $ce = 0;

    public int $colspan = 1;

    public int $rowspan = 1;

    public int $row = 0;

    public int $col = 0;

    public string $span = '';

    public bool $joined = false;

    /**
     * Content line spans: the cell's own content, then the fragments of continuation rows.
     *
     * @var array<int>
     */
    public array $lines = [];

    /**
     * @param int $start
     * @param int $end
     */
    public function __construct(public int $start, public int $end)
    {
    }
}
