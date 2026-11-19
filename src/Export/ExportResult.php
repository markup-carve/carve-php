<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Export;

/**
 * What an export returns: its text and the losses of the run, entries of the `render-loss` channel with the pointer of
 * their node.
 */
final class ExportResult
{
    /**
     * @param string $output
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $diagnostics
     */
    public function __construct(public readonly string $output, public readonly array $diagnostics)
    {
    }

    /**
     * @return array<string>
     */
    public function codes(): array
    {
        return array_map(static fn ($d): string => $d->code, $this->diagnostics);
    }
}
