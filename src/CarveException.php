<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

use Throwable;

/**
 * Implemented by every error a public call throws (C2-T-006, C2-T-040), so a host can catch them all at once.
 */
interface CarveException extends Throwable
{
}
