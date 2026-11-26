<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use MarkupCarve\Carve\CarveException;
use RuntimeException;

/**
 * C2-W-134: a `format` or `write` call that returns only source and whose writer reported a loss.
 */
final class CarveWriteError extends RuntimeException implements CarveException
{
    /**
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $diagnostics
     */
    public function __construct(public readonly array $diagnostics)
    {
        $first = $diagnostics[0] ?? null;
        $message = $first === null ? 'the writer refused the tree' : $first->code . ': ' . $first->message . ' at ' . (string)$first->path;
        parent::__construct($message);
    }
}
