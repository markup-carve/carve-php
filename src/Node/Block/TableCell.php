<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * Table cell
 */
class TableCell extends BlockNode
{
    /**
     * @var string
     */
    public const ALIGN_DEFAULT = 'default';

    /**
     * @var string
     */
    public const ALIGN_LEFT = 'left';

    /**
     * @var string
     */
    public const ALIGN_CENTER = 'center';

    /**
     * @var string
     */
    public const ALIGN_RIGHT = 'right';

    /**
     * @var string
     */
    public const VALIGN_DEFAULT = 'default';

    /**
     * @var string
     */
    public const VALIGN_TOP = 'top';

    /**
     * @var string
     */
    public const VALIGN_MIDDLE = 'middle';

    /**
     * @var string
     */
    public const VALIGN_BOTTOM = 'bottom';

    protected string $verticalAlignment = self::VALIGN_DEFAULT;

    protected bool $hasExplicitVerticalAlignment = false;

    /**
     * Whether the alignment is the CELL's own rather than the column's.
     *
     * Null infers it from the alignment argument, which is what a caller
     * building a cell by hand means: an alignment passed to the constructor is
     * that cell's. Only the parser says `false` alongside a non-default
     * alignment, for a cell that merely inherits its column's.
     */
    protected bool $hasExplicitAlignment = false;

    public function __construct(
        protected bool $isHeader = false,
        protected string $alignment = self::ALIGN_DEFAULT,
        protected int $rowspan = 1,
        protected int $colspan = 1,
        protected ?string $spanMarker = null,
        ?bool $hasExplicitAlignment = null,
    ) {
        $this->hasExplicitAlignment = $hasExplicitAlignment ?? ($alignment !== self::ALIGN_DEFAULT);
    }

    public function isHeader(): bool
    {
        return $this->isHeader;
    }

    public function getAlignment(): string
    {
        return $this->alignment;
    }

    public function hasExplicitAlignment(): bool
    {
        return $this->hasExplicitAlignment;
    }

    public function setExplicitAlignment(bool $explicit): void
    {
        $this->hasExplicitAlignment = $explicit;
    }

    public function getVerticalAlignment(): string
    {
        return $this->verticalAlignment;
    }

    public function setVerticalAlignment(string $alignment, bool $explicit = true): void
    {
        $this->verticalAlignment = $alignment;
        $this->hasExplicitVerticalAlignment = $explicit;
    }

    public function hasExplicitVerticalAlignment(): bool
    {
        return $this->hasExplicitVerticalAlignment;
    }

    public function getRowspan(): int
    {
        return $this->rowspan;
    }

    public function setRowspan(int $rowspan): void
    {
        $this->rowspan = $rowspan;
    }

    public function getColspan(): int
    {
        return $this->colspan;
    }

    public function setColspan(int $colspan): void
    {
        $this->colspan = $colspan;
    }

    public function getSpanMarker(): ?string
    {
        return $this->spanMarker;
    }

    public function setSpanMarker(?string $spanMarker): void
    {
        $this->spanMarker = $spanMarker;
    }

    public function getType(): string
    {
        return 'table_cell';
    }
}
