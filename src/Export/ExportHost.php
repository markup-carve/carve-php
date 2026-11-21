<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Export;

use Closure;

/**
 * The current target's core fallback writer for a registered extension.
 */
final class ExportHost
{
    /**
     * @param string $target
     * @param \Closure(): string $fallback
     */
    public function __construct(public readonly string $target, private readonly Closure $fallback)
    {
    }

    public function fallback(): string
    {
        return ($this->fallback)();
    }
}
