<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Json;

use MarkupCarve\Carve\CarveException;
use UnexpectedValueException;

final class TreeError extends UnexpectedValueException implements CarveException
{
    public function __construct(public readonly string $reason, public readonly string $pointer)
    {
        parent::__construct($reason . ' at ' . ($pointer === '' ? '/' : $pointer));
    }
}
