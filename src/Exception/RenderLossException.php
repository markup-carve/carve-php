<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Exception;

use MarkupCarve\Carve\RenderResult;
use RuntimeException;

final class RenderLossException extends RuntimeException
{
    /**
     * @var list<array<string, mixed>>
     */
    public readonly array $losses;

    public readonly int $totalLosses;

    public readonly bool $truncated;

    public function __construct(RenderResult $result)
    {
        $this->losses = $result->losses;
        $this->totalLosses = $result->totalLosses;
        $this->truncated = $result->truncated;
        parent::__construct(sprintf('Render would drop %d raw node%s.', $this->totalLosses, $this->totalLosses === 1 ? '' : 's'));
    }
}
