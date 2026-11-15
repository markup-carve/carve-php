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

    public string $value = '';

    /**
     * @param bool $isRow
     * @param int $start
     * @param int $end
     */
    public function __construct(public bool $isRow, public int $start, public int $end)
    {
    }
}
