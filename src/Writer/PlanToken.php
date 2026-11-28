<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

final class PlanToken
{
    public int $pair = -1;

    public function __construct(
        public int $kind,
        public int $start,
        public int $end,
        public int $mark,
        public int $flags,
    ) {
    }
}
