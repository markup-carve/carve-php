<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * Closed colon boundaries shared by unchanged container bodies.
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
