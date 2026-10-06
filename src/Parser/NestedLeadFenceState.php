<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * Progress through an append-only marker-lead item's nested fence and tail.
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
