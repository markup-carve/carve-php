<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Inline;

/**
 * The tokens or items of the content of one bracket construct or delimiter span (see Scanner).
 */
final class TokenList
{
    /**
     * @param array<int, array<mixed>> $list
     * @param int $open Start of the content (end of the opener) of a bracket construct.
     * @param int $close Start of its closing bracket.
     */
    public function __construct(public array $list, public int $open = -1, public int $close = -1)
    {
    }
}
