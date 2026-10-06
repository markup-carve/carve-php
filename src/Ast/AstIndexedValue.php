<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

final class AstIndexedValue
{
    /**
     * @param mixed $value
     * @param int $id
     * @param array<int|string, self> $children
     */
    public function __construct(
        public readonly mixed $value,
        public readonly int $id,
        public readonly array $children = [],
    ) {
    }
}
