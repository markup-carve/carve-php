<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Export;

use MarkupCarve\Carve\CarveException;
use RuntimeException;

/**
 * Thrown under `strictLosses` when an export lost something.
 */
final class CarveExportError extends RuntimeException implements CarveException
{
    /**
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $diagnostics
     */
    public function __construct(public readonly array $diagnostics)
    {
        $codes = implode(', ', array_map(static fn ($d): string => $d->code, $diagnostics));
        $count = count($diagnostics);
        parent::__construct('the export lost ' . $count . ' construct' . ($count === 1 ? '' : 's') . ': ' . $codes);
    }
}
