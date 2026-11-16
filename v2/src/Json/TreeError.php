<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Json;

use UnexpectedValueException;

final class TreeError extends UnexpectedValueException
{
    public function __construct(public readonly string $reason, public readonly string $pointer)
    {
        parent::__construct($reason . ' at ' . ($pointer === '' ? '/' : $pointer));
    }
}
