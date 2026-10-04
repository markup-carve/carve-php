<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * Colon boundaries shared by unchanged container bodies.
 *
 * @internal
 */
final class ColonFenceIndex
{
    /**
     * @var array<int, int>
     */
    public array $ends = [];
}
