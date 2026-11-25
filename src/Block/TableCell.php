<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Block;

use MarkupCarve\Carve\Attrs;

/**
 * One cell of a pipe table row while the table is open (C2-B-171 to C2-B-173).
 */
final class TableCell
{
    public bool $header = false;

    public ?Attrs $attrs = null;

    public ?string $align = null;

    public ?string $valign = null;

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
     * C2-L-020: for each pair of `$lines`, its continuation line, 0 for the cell's own row, the rows below the cell's
     * own row it stands in, and the covered column it stands in, counted from the cell's own column.
     *
     * @var array<array{0: int, 1: int, 2?: int}>
     */
    public array $wrapAt = [];

    /**
     * @param int $start
     * @param int $end
     */
    public function __construct(public int $start, public int $end)
    {
    }
}
