<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

/**
 * Where a `+` continuation row joined content onto a table cell (CARVE-P12-069).
 * Renders as one space; the Carve writer writes the `+` line back.
 */
class CellWrap extends InlineNode
{
    /**
     * @param int $line The continuation line of the named row, counted from 1.
     * @param int $row Rows below the spanning cell's own row whose `+` line this is.
     * @param int $col Columns right of the cell's left edge where the part was written.
     */
    public function __construct(protected int $line, protected int $row = 0, protected int $col = 0)
    {
    }

    public function getLine(): int
    {
        return $this->line;
    }

    public function getRow(): int
    {
        return $this->row;
    }

    public function getCol(): int
    {
        return $this->col;
    }

    public function getType(): string
    {
        return 'cell_wrap';
    }
}
