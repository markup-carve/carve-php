<?php

declare(strict_types=1);

namespace Carve\Transform;

use Carve\Node\Document;
use Carve\Renderer\RendererInterface;

/**
 * Optional transformer hook for render-specific derived trees.
 */
interface RenderAwareTransformerInterface extends TransformerInterface
{
    public function transformForRenderer(Document $document, RendererInterface $renderer): Document;
}
