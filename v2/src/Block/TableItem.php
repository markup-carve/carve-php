<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Block;

use MarkupCarve\Carve2\Attrs;

/**
 * A row or a comment-only line of an open pipe table, in source order (C2-A-086).
 */
final class TableItem
{
    /**
     * @var array<\MarkupCarve\Carve2\Block\TableCell>
     */
    public array $cells = [];

    public ?Attrs $attrs = null;

    /**
     * Fragment spans of each continuation row below the row (C2-B-175).
     *
     * @var array<array<int>>
     */
    public array $cont = [];

    /**
     * @var array<array<int>> The byte after each pipe that opens a cell of a continuation row, row by row.
     */
    public array $contSlots = [];

    public string $value = '';

    public int $continuationEnd = -1;

    /**
     * @param bool $isRow
     * @param int $start
     * @param int $end
     */
    public function __construct(public bool $isRow, public int $start, public int $end)
    {
    }
}
