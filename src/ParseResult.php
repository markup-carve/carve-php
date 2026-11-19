<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

use MarkupCarve\Carve\Diagnostics\Diagnostic;

/**
 * The tree of a parse and the entries of its parse channel.
 */
final class ParseResult
{
    /**
     * @param \MarkupCarve\Carve\Node $tree
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $diagnostics
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
