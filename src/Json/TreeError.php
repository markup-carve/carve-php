<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Json;

use MarkupCarve\Carve\CarveException;
use Throwable;
use UnexpectedValueException;

final class TreeError extends UnexpectedValueException implements CarveException
{
    public function __construct(public readonly string $reason, public readonly string $pointer, ?Throwable $previous = null)
    {
        parent::__construct($reason . ' at ' . ($pointer === '' ? '/' : $pointer), 0, $previous);
    }
}
