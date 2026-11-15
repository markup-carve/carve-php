<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Inline;

/**
 * The tokens or items of the content of one bracket construct or delimiter span (see Scanner).
 */
final class TokenList
{
    /**
     * @param array<int, array<mixed>> $list
     */
    public function __construct(public array $list)
    {
    }
}
