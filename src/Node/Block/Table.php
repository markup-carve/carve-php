<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

/**
 * Table container
 */
class Table extends BlockNode
{
    /**
     * @var list<array{align?: string, valign?: string, width?: float}>
     */
    protected array $columns = [];

    /**
     * @param list<array{align?: string, valign?: string, width?: float}> $columns
     */
    public function setColumns(array $columns): void
    {
        $this->columns = $columns;
    }

    /**
     * @return list<array{align?: string, valign?: string, width?: float}>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * @var array{headRows: int, footRows: int, headAttrs?: array{id?: string, classes?: list<string>, keyValues?: array<string, string>, order?: list<string>}, footAttrs?: array{id?: string, classes?: list<string>, keyValues?: array<string, string>, order?: list<string>}, bodies: list<array{headRows: int, bodyRows: int, rowHeadColumns?: int, attrs?: array{id?: string, classes?: list<string>, keyValues?: array<string, string>, order?: list<string>}}> }|null
     */
    protected ?array $rowGroups = null;

    /**
     * @return array{headRows: int, footRows: int, headAttrs?: array{id?: string, classes?: list<string>, keyValues?: array<string, string>, order?: list<string>}, footAttrs?: array{id?: string, classes?: list<string>, keyValues?: array<string, string>, order?: list<string>}, bodies: list<array{headRows: int, bodyRows: int, rowHeadColumns?: int, attrs?: array{id?: string, classes?: list<string>, keyValues?: array<string, string>, order?: list<string>}}> }|null
     */
    public function getRowGroups(): ?array
    {
        return $this->rowGroups;
    }

    /**
     * @param array{headRows: int, footRows: int, headAttrs?: array{id?: string, classes?: list<string>, keyValues?: array<string, string>, order?: list<string>}, footAttrs?: array{id?: string, classes?: list<string>, keyValues?: array<string, string>, order?: list<string>}, bodies: list<array{headRows: int, bodyRows: int, rowHeadColumns?: int, attrs?: array{id?: string, classes?: list<string>, keyValues?: array<string, string>, order?: list<string>}}> }|null $groups
     */
    public function setRowGroups(?array $groups): void
    {
        $this->rowGroups = $groups;
    }

    protected ?Caption $caption = null;

    /**
     * @var array<\MarkupCarve\Carve\Node\Inline\InlineNode>|null Optional abbreviated
     *      navigation caption supplied by a structured format. Ordinary renderers ignore it.
     */
    protected ?array $shortCaption = null;

    /**
     * Original separator widths for round-trip preservation
     *
     * @var array<int>|null
     */
    protected ?array $separatorWidths = null;

    public function getType(): string
    {
        return 'table';
    }

    public function setCaption(Caption $caption): void
    {
        $this->caption = $caption;
    }

    public function getCaption(): ?Caption
    {
        return $this->caption;
    }

    public function hasCaption(): bool
    {
        return $this->caption !== null;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Inline\InlineNode>|null $shortCaption
     */
    public function setShortCaption(?array $shortCaption): void
    {
        $this->shortCaption = $shortCaption;
    }

    /**
     * @return array<\MarkupCarve\Carve\Node\Inline\InlineNode>|null
     */
    public function getShortCaption(): ?array
    {
        return $this->shortCaption;
    }

    /**
     * Set the original separator widths from parsing
     *
     * @param array<int> $widths Array of separator widths per column
     */
    public function setSeparatorWidths(array $widths): void
    {
        $this->separatorWidths = $widths;
    }

    /**
     * Get the original separator widths
     *
     * @return array<int>|null Array of widths or null if not set
     */
    public function getSeparatorWidths(): ?array
    {
        return $this->separatorWidths;
    }
}
