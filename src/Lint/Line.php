<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

/**
 * One source line: its text without the line ending, its byte range and what the tree says about it.
 */
final class Line
{
    public function __construct(
        public string $text,
        public int $start,
        public int $end,
        public int $number,
        public string $path = '',
        public bool $opaque = false,
        public bool $paragraph = false,
    ) {
    }
}
