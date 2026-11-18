<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Layout;

use MarkupCarve\Carve2\CarveException;
use UnexpectedValueException;

/**
 * A source-layout record a reader refuses (C2-L-008, C2-L-058); `pointer` names the field inside the record.
 */
final class LayoutError extends UnexpectedValueException implements CarveException
{
    public function __construct(public readonly string $reason, public readonly string $pointer)
    {
        parent::__construct($reason . ' at ' . ($pointer === '' ? '/' : $pointer));
    }
}
