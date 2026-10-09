<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

final class DjotListContext
{
    public bool $loose = false;

    public bool $continuation = false;

    public function __construct(
        public readonly int $column,
        public readonly int $content,
        public readonly int $target,
        public string $kind,
        public string $bullet,
        public int $start,
    ) {
    }
}
