<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * Holds a reference definition's URL and attributes
 */
class ReferenceDefinition
{
    /**
     * @param string $url
     * @param array<string, string> $attributes
     * @param int $line Line number where reference was defined (0-indexed)
     * @param string|null $title
     * @param bool $fromHeading Whether the definition was DERIVED from a
     *   heading (PART 11 R1) rather than written as a `[label]: url` line. The
     *   canonical writer needs the distinction: a heading-derived reference has
     *   no definition line to reproduce, so the authored `[text][]` form is the
     *   only record of what the author wrote.
     * @param string|null $rawLabel authored label spelling for canonical output
     */
    public function __construct(
        public readonly string $url,
        public readonly array $attributes = [],
        public readonly int $line = 0,
        public readonly ?string $title = null,
        public readonly bool $fromHeading = false,
        public readonly ?string $rawLabel = null,
    ) {
    }
}
