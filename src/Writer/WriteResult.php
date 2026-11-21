<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * The source the writer gives and its render-loss report (C2-W-134).
 */
final class WriteResult
{
    /**
     * @param string $source
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $diagnostics
     */
    public function __construct(public readonly string $source, public readonly array $diagnostics)
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
