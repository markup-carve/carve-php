<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;

/**
 * A named host pass over a copy of the resolved render view (C2-X-053).
 */
final class DocumentTransform
{
    /**
     * @param string $name
     * @param \Closure(\MarkupCarve\Carve\Node): \MarkupCarve\Carve\Node $run
     */
    public function __construct(public readonly string $name, public readonly Closure $run)
    {
    }
}
