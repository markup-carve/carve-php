<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Output;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Node;

/**
 * The `html` target: `Carve::render()` of a tree, with the render options.
 */
final class HtmlWriter implements OutputWriter
{
    public function target(): string
    {
        return 'html';
    }

    /**
     * @param \MarkupCarve\Carve\Node $tree
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public function write(Node $tree, array $options = []): string
    {
        return Carve::render($tree, $options);
    }
}
