<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Json;

use RuntimeException;

/**
 * A value `ViewCheck` does not read exactly as the full tree check does; the full check decides.
 */
final class Unmirrored extends RuntimeException
{
}
