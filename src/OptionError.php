<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

use InvalidArgumentException;
use Throwable;

/**
 * The one documented exception: an option name or value that options.json does not allow.
 */
final class OptionError extends InvalidArgumentException implements CarveException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?string $path = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
