<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Cli;

use RuntimeException;

/**
 * An input file the command cannot read, or AST JSON that is not a tree (C2-W-101).
 */
final class InputError extends RuntimeException
{
    public function __construct(string $message, public readonly string $category = 'input-unreadable')
    {
        parent::__construct($message);
    }
}
