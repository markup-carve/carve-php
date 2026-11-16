<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2;

use MarkupCarve\Carve2\Diagnostics\Diagnostic;

/**
 * The tree of a parse and the entries of its parse channel.
 */
final class ParseResult
{
    /**
     * @param \MarkupCarve\Carve2\Node $tree
     * @param array<\MarkupCarve\Carve2\Diagnostics\Diagnostic> $diagnostics
     */
    public function __construct(public readonly Node $tree, public readonly array $diagnostics)
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
