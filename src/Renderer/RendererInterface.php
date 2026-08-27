<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use MarkupCarve\Carve\Node\Document;

/**
 * Interface for renderers that convert a Document AST to string output
 */
interface RendererInterface
{
    /**
     * Absolute recursion ceiling for every public Document-accepting render path.
     *
     * @var int
     */
    public const MAX_RENDER_DEPTH = 512;

    public function render(Document $document): string;
}
