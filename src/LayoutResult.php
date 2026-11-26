<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

/**
 * The tree of a parse and its source-layout record (source-layout.md), which the caller asked for (C2-L-001).
 */
final class LayoutResult
{
    /**
     * @param \MarkupCarve\Carve\Node $tree
     * @param array<string, mixed> $layout
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $diagnostics
     * @param int $suppressedDiagnostics The include failures the cap held back (C2-T-024).
     */
    public function __construct(
        public readonly Node $tree,
        public readonly array $layout,
        public readonly array $diagnostics,
        public readonly int $suppressedDiagnostics = 0,
    ) {
    }
}
