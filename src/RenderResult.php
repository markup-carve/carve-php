<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

use MarkupCarve\Carve\Diagnostics\Diagnostic;

/**
 * The HTML of a render and the entries of its parse and render-loss channels.
 */
final class RenderResult
{
    /**
     * @param string $html
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $diagnostics
     * @param int $suppressedDiagnostics The entries a cap held back (C2-T-024, C2-H-205).
     */
    public function __construct(
        public readonly string $html,
        public readonly array $diagnostics,
        public readonly int $suppressedDiagnostics = 0,
    ) {
    }

    /**
     * @return array<string>
     */
    public function codes(): array
    {
        return array_map(static fn (Diagnostic $d): string => $d->code, $this->diagnostics);
    }
}
