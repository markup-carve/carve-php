<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Json;

use MarkupCarve\Carve\CarveException;
use UnexpectedValueException;

/**
 * A patch that cannot be applied: `pointer` points into the patch, or into the tree the patch gave (C2-A-167).
 */
final class PatchError extends UnexpectedValueException implements CarveException
{
    public function __construct(public readonly string $pointer, public readonly string $reason)
    {
        parent::__construct(($pointer === '' ? '/' : $pointer) . ': ' . $reason);
    }
}
