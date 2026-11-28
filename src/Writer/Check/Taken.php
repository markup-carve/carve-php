<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

/**
 * Where the siblings a span took so far end, and the spelling and start of the last of them (carve-js `Taken`).
 */
final class Taken
{
    public function __construct(public int $offset, public int $start, public string $spelled)
    {
    }
}
