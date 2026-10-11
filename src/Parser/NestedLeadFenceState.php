<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * Progress through a marker lead's nested fence and tail in either host.
 *
 * @internal
 */
final class NestedLeadFenceState
{
    public bool $initialized = false;

    public int $column = 0;

    public string $char = '';

    public int $length = 0;

    public int $nextLine = 1;

    public bool $closed = false;

    public bool $onlyBlankBelow = true;

    public TrailingBlockState $trailing;

    public function __construct()
    {
        $this->trailing = new TrailingBlockState();
    }
}
