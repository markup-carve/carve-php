<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Output;

use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\Node;

/**
 * The `carve` target: `Carve2::write()` of a tree, with `alignTables` and `extensions`.
 */
final class CarveWriter implements OutputWriter
{
    public function target(): string
    {
        return 'carve';
    }

    /**
     * @param \MarkupCarve\Carve2\Node $tree
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public function write(Node $tree, array $options = []): string
    {
        return Carve2::write($tree, null, $options);
    }
}
