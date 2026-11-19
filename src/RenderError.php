<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

use RuntimeException;

final class RenderError extends RuntimeException implements CarveException
{
    /**
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $diagnostics
     */
    public function __construct(public readonly array $diagnostics = [])
    {
        parent::__construct('the render has a loss under strictLosses');
    }
}
