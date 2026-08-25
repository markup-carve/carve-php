<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Exception;

use MarkupCarve\Carve\RenderResult;
use RuntimeException;

final class RenderLossException extends RuntimeException
{
    public function __construct(public readonly RenderResult $result)
    {
        parent::__construct(sprintf('Render would drop %d raw node%s.', $result->totalLosses, $result->totalLosses === 1 ? '' : 's'));
    }
}
