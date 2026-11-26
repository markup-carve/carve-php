<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\Node;

/**
 * Internal: one cell of a list table and its spans.
 */
final class ListTableCell
{
    public int $rowspan = 1;

    public int $colspan = 1;

    public bool $skip = false;

    public function __construct(public readonly Node $item, public readonly ?string $marker)
    {
    }
}
