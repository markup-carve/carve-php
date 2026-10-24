<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

final class TableWork
{
    private int $width = 0;

    private int $column = 0;

    public function begin(int $width, int $column): void
    {
        $this->width = $width;
        $this->column = $column;
    }

    public function end(): void
    {
        $this->width = 0;
        $this->column = 0;
    }

    public function active(): bool
    {
        return $this->width > 0;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function column(): int
    {
        return $this->column;
    }
}
