<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Output;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Node;

/**
 * The `carve` target: `Carve::write()` of a tree, with `alignTables` and `extensions`.
 */
final class CarveWriter implements OutputWriter
{
    public function target(): string
    {
        return 'carve';
    }

    /**
     * @param \MarkupCarve\Carve\Node $tree
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public function write(Node $tree, array $options = []): string
    {
        return Carve::write($tree, null, $options);
    }
}
