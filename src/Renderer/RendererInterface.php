<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use MarkupCarve\Carve\Node\Document;

/**
 * Interface for renderers that convert a Document AST to string output
 */
interface RendererInterface
{
    public function render(Document $document): string;
}
