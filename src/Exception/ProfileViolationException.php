<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Exception;

use MarkupCarve\Carve\ProfileViolation;
use RuntimeException;

/**
 * Exception thrown when profile violations occur in ACTION_ERROR mode
 */
class ProfileViolationException extends RuntimeException
{
    /**
     * @param list<\MarkupCarve\Carve\ProfileViolation> $violations
     */
    public function __construct(public readonly array $violations)
    {
        $messages = array_map(
            fn (ProfileViolation $v) => $v->getMessage(),
            $violations,
        );
        parent::__construct('Profile violations: ' . implode('; ', $messages));
    }
}
