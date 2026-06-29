<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use MarkupCarve\Carve\Node\Document;

/**
 * Optional extension lifecycle hook for deriving a document before render().
 */
interface BeforeRenderExtensionInterface extends ExtensionInterface
{
    /**
     * Return the document that should be rendered.
     *
     * Implementations may return the original document or a transformed copy.
     */
    public function beforeRender(Document $document): Document;
}
