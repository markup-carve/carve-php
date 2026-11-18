<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2;

use RuntimeException;

final class RenderError extends RuntimeException
{
    /**
     * @param array<\MarkupCarve\Carve2\Diagnostics\Diagnostic> $diagnostics
     */
    public function __construct(public readonly array $diagnostics = [])
    {
        parent::__construct('the render has a loss under strictLosses');
    }
}
