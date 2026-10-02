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

    /**
     * The partition the table's own `header-rows` / `footer-rows` attributes
     * STATE, or null when they state none that partitions the rows.
     *
     * An authored count is explicit structure, so it belongs on the wire under
     * `rowGroups` (PART 12, `resources/ast-schema.json`) and not only in an
     * attribute a foreign reader has to know how to interpret. carve-php
     * published the attributes alone, so carve-js and carve-rs fed its JSON
     * rendered every row as a body row (markup-carve/carve-php#2633).
     *
     * The three refusals are the same ones the renderer falls back on: a count
     * that is not a number, and a head plus foot that overruns the rows. A
     * partition that does not account for every row exactly once is invalid
     * under the schema, and the decoder refuses it.
     *
     * @return array{headRows: int, footRows: int, bodies: list<array{headRows: int, bodyRows: int, rowHeadColumns?: int}>}|null
     */
    public function statedRowGroups(): ?array
    {
        if (
            $this->getAttribute('header-rows') === null && $this->getAttribute('footer-rows') === null
            && $this->getAttribute('body-rows') === null && $this->getAttribute('body-header-rows') === null
            && $this->getAttribute('body-header-cols') === null
        ) {
            return null;
        }

        $head = self::statedRowCount($this->getAttribute('header-rows'));
        $foot = self::statedRowCount($this->getAttribute('footer-rows'));
        if ($head === null || $foot === null) {
            return null;
        }

        $rows = 0;
        foreach ($this->getChildren() as $child) {
            if ($child instanceof TableRow) {
                $rows++;
            }
        }
        if ($head > $rows || $foot > $rows - $head) {
            return null;
        }

        $rawBodies = $this->getAttribute('body-rows');
        $rawHeaders = $this->getAttribute('body-header-rows');
        $rawColumns = $this->getAttribute('body-header-cols');
        if ($rawBodies !== null) {
            $counts = trim($rawBodies) === '' ? [] : explode(',', $rawBodies);
            $headers = $rawHeaders === null ? null : explode(',', $rawHeaders);
            $columns = $rawColumns === null ? null : explode(',', $rawColumns);
            if (($headers !== null && count($headers) !== count($counts)) || ($columns !== null && count($columns) !== count($counts))) {
                return null;
            }
            $remaining = $rows - $head - $foot;
            $bodies = [];
            foreach ($counts as $index => $rawCount) {
                $bodyRows = self::bodyCount($rawCount);
                $bodyHead = $headers === null ? 0 : self::bodyCount($headers[$index]);
                $columnValue = trim($columns[$index] ?? '');
                $rowHeadColumns = $columnValue === '' ? null : self::bodyCount($columnValue);
                if (
                    $bodyRows === null || $bodyHead === null || ($columnValue !== '' && $rowHeadColumns === null)
                    || $bodyHead > $remaining || $bodyRows > $remaining - $bodyHead
                ) {
                    return null;
                }
                $remaining -= $bodyHead + $bodyRows;
                $body = ['headRows' => $bodyHead, 'bodyRows' => $bodyRows];
                if ($rowHeadColumns !== null) {
                    $body['rowHeadColumns'] = $rowHeadColumns;
                }
                $bodies[] = $body;
            }

            return $remaining === 0 ? ['headRows' => $head, 'bodies' => $bodies, 'footRows' => $foot] : null;
        }
        if ($rawHeaders !== null || $rawColumns !== null) {
            return null;
        }

        return [
            'headRows' => $head,
            'bodies' => $rows > $head + $foot ? [['headRows' => 0, 'bodyRows' => $rows - $head - $foot]] : [],
            'footRows' => $foot,
        ];
    }

    private static function bodyCount(string $value): ?int
    {
        $value = trim($value);
        if (preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }
        $digits = ltrim($value, '0');
        $maximum = '9007199254740991';
        if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            return null;
        }

        return (int)$value;
    }

    /**
     * A row count an attribute states: absent is none, a valueless attribute is
     * one row, and anything but digits states nothing at all.
     */
    private static function statedRowCount(mixed $value): ?int
    {
        if (!is_string($value)) {
            return 0;
        }
        if (trim($value) === '') {
            return 1;
        }

        return self::bodyCount($value);
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
