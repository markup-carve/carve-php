<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\Attrs;

/**
 * Internal: the rows of a list table with what its cells read.
 */
final class ListTableData
{
    /**
     * @param array<array<\MarkupCarve\Carve\Extension\ListTableCell>> $rows
     * @param array<bool> $rowHeads
     * @param \MarkupCarve\Carve\Attrs|null $attrs
     * @param int $head
     * @param int $headCols
     * @param int $width
     * @param array<string> $aligns
     * @param array<string> $valigns
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $rowHeads,
        public readonly ?Attrs $attrs,
        public readonly int $head,
        public readonly int $headCols,
        public readonly int $width,
        public readonly array $aligns = [],
        public readonly array $valigns = [],
    ) {
    }
}
