<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * Saved outer attachment while the ownership walk reads a nested attachment.
 *
 * @internal
 */
final class AttachmentParent
{
    /**
     * @param array{frames: array<int, \MarkupCarve\Carve\Parser\OwnershipFrame>, column: int, depth: int, from: int, item: bool, kind: string, parent?: self|null} $host
     */
    public function __construct(public array $host)
    {
    }
}
