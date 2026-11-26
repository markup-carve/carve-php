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
     * @param string $path
     * @param string $parent
     * @param int $index
     * @param string $type
     * @param int $start
     * @param int $end
     * @param int $startLine 0 when the node has no position.
     * @param int $endLine
     */
    public function __construct(
        public array $node,
        public string $path,
        public string $parent,
        public int $index,
        public string $type,
        public int $start = 0,
        public int $end = 0,
        public int $startLine = 0,
        public int $endLine = 0,
    ) {
    }
}
