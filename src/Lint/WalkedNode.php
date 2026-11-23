<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

/**
 * One node of the lint walk, in source walk order, for the data rule model. Declared fields take well under half
 * the memory of the same fields in an array, and the walk holds one per node.
 */
final class WalkedNode
{
    /**
     * @param array<string, mixed> $node
     * @param int $index
     * @param string $parent
     * @param string $path
     * @param array<string, int>|null $pos
     * @param string $type
     */
    public function __construct(
        public array $node,
        public string $path,
        public string $parent,
        public int $index,
        public ?array $pos,
        public string $type,
    ) {
    }
}
