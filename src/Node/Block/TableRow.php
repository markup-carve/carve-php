<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * Table row
 */
class TableRow extends BlockNode
{
    /**
     * The number of source `+` lines following this row.
     */
    protected int $continuationLines = 0;

    public function __construct(protected bool $isHeader = false)
    {
    }

    public function getContinuationLines(): int
    {
        return $this->continuationLines;
    }

    public function setContinuationLines(int $continuationLines): void
    {
        $this->continuationLines = $continuationLines;
    }

    public function isHeader(): bool
    {
        return $this->isHeader;
    }

    public function getType(): string
    {
        return 'table_row';
    }
}
