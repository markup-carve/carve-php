<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

final class FenceWork
{
    public bool $active = false;

    public string $char = '';

    public int $length = 0;

    public int $strip = 0;

    public int $itemColumn = 0;

    public int $shift = 0;

    public int $outputIndex = -1;

    public string $info = '';

    public function begin(string $marker, int $strip, int $itemColumn, int $shift, int $outputIndex, string $info): void
    {
        $this->active = true;
        $this->char = $marker[0];
        $this->length = strlen($marker);
        $this->strip = $strip;
        $this->itemColumn = $itemColumn;
        $this->shift = $shift;
        $this->outputIndex = $outputIndex;
        $this->info = $info;
    }

    public function end(): void
    {
        $this->active = false;
        $this->char = '';
        $this->length = 0;
        $this->strip = 0;
        $this->itemColumn = 0;
        $this->shift = 0;
        $this->outputIndex = -1;
        $this->info = '';
    }
}
