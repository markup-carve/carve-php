<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2;

/**
 * The tree of a parse and its source-layout record (source-layout.md), which the caller asked for (C2-L-001).
 */
final class LayoutResult
{
    /**
     * @param \MarkupCarve\Carve2\Node $tree
     * @param array<string, mixed> $layout
     * @param array<\MarkupCarve\Carve2\Diagnostics\Diagnostic> $diagnostics
     */
    public function __construct(public readonly Node $tree, public readonly array $layout, public readonly array $diagnostics)
    {
    }
}
