<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Exception;

use RuntimeException;

class HtmlImportDepthExceededException extends RuntimeException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct(sprintf('HTML import refused a tree deeper than %d element levels.', $limit));
    }
}
