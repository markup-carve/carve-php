<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Exception;

use RuntimeException;

/**
 * The canonical Carve writer cannot spell an AST node without changing it.
 */
class SourceUnspellableException extends RuntimeException
{
    public function __construct(public readonly string $nodeType, public readonly string $reason)
    {
        parent::__construct(sprintf('The Carve renderer cannot spell %s: %s.', $nodeType, $reason));
    }
}
