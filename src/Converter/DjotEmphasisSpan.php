<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

final class DjotEmphasisSpan
{
    /**
     * @var list<self>
     */
    public array $children = [];

    /**
     * @var array<string, true>
     */
    public array $kinds;

    public function __construct(
        public readonly int $start,
        public readonly int $openEnd,
        public readonly int $close,
        public readonly int $end,
        public readonly string $kind,
        public readonly bool $forced,
    ) {
        $this->kinds = [$kind => true];
    }
}
