<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Transform;

use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Renderer\RendererInterface;

/**
 * Optional transformer hook for render-specific derived trees.
 */
interface RenderAwareTransformerInterface extends TransformerInterface
{
    public function transformForRenderer(Document $document, RendererInterface $renderer): Document;
}
