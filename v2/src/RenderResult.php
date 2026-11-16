<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2;

use MarkupCarve\Carve2\Diagnostics\Diagnostic;

/**
 * The HTML of a render and the entries of its parse and render-loss channels.
 */
final class RenderResult
{
    /**
     * @param string $html
     * @param array<\MarkupCarve\Carve2\Diagnostics\Diagnostic> $diagnostics
     */
    public function __construct(public readonly string $html, public readonly array $diagnostics)
    {
    }

    /**
     * @return array<string>
     */
    public function codes(): array
    {
        return array_map(static fn (Diagnostic $d): string => $d->code, $this->diagnostics);
    }
}
