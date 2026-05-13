<?php

declare(strict_types=1);

namespace Carve\Node;

/**
 * Implemented by nodes that store raw text content directly.
 */
interface ContentNodeInterface
{
    public function getContent(): string;
}
