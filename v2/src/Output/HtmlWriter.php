<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Output;

use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\Node;

/**
 * The `html` target: `Carve2::render()` of a tree, with the render options.
 */
final class HtmlWriter implements OutputWriter
{
    public function target(): string
    {
        return 'html';
    }

    /**
     * @param \MarkupCarve\Carve2\Node $tree
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public function write(Node $tree, array $options = []): string
    {
        return Carve2::render($tree, $options);
    }
}
