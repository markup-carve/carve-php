<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * A nested lead's first closer in appended entries with an extensible tail.
 *
 * @internal
 */
final class CollectedFenceView
{
    public bool $initialized = false;

    public bool $leadOpensBlock = false;

    public int $column = 0;

    /**
     * @var array{fence: string, length: int, char?: string}|null
     */
    public ?array $opener = null;

    public int $nextEntry = 1;

    public bool $closed = false;

    public int $tailLength = -1;

    public bool $tailClosed = false;
}
