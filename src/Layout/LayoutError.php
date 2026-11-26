<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Layout;

use MarkupCarve\Carve\CarveException;
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
