<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2;

use InvalidArgumentException;

/**
 * The one documented exception: an option name or value that options.json does not allow.
 */
final class OptionError extends InvalidArgumentException implements CarveException
{
}
