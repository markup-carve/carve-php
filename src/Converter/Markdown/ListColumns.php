<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

final class ListColumns
{
    /**
     * @var list<int>
     */
    private array $columns = [];

    public function isEmpty(): bool
    {
        return $this->columns === [];
    }

    public function count(): int
    {
        return count($this->columns);
    }

    public function innermost(): int
    {
        return $this->columns === [] ? 0 : $this->columns[array_key_last($this->columns)];
    }

    public function outermost(): int
    {
        return $this->columns[0] ?? 0;
    }

    /**
     * @return list<int>
     */
    public function all(): array
    {
        return $this->columns;
    }

    public function push(int $column): void
    {
        $this->columns[] = $column;
    }

    public function popAbove(int $column): void
    {
        while (!$this->isEmpty() && $this->innermost() > $column) {
            array_pop($this->columns);
        }
    }

    public function clear(): void
    {
        $this->columns = [];
    }
}
