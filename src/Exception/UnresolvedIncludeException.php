<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Exception;

use RuntimeException;

/**
 * A refusal that names where the target WOULD appear (PART 9 section 19 I11).
 *
 * A host watches that path and rebuilds when the file arrives; the directive as
 * written names nothing it can watch from a file below the root. A resolver
 * that cannot say where the target would be throws an ordinary exception, and
 * the dependency keeps the directive's spelling.
 */
class UnresolvedIncludeException extends RuntimeException
{
    public function __construct(string $message, protected string $targetId)
    {
        parent::__construct($message);
    }

    public function getTargetId(): string
    {
        return $this->targetId;
    }
}
