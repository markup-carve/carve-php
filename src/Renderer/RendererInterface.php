<?php

declare(strict_types=1);

namespace Carve\Renderer;

use Carve\Node\Document;

/**
 * Interface for renderers that convert a Document AST to string output
 */
interface RendererInterface
{
    public function render(Document $document): string;
}
