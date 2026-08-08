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
     *
     * $context is READ-ONLY and carries what the hook cannot otherwise know:
     * the render options, the effective mode for the target format, and whether
     * the final target is HTML. The hook runs before the render starts, so a
     * hook that produces output of its own has nothing to inherit and would
     * otherwise produce it with defaults - see {@see BeforeRenderContext} and
     * the spec's extension contract (docs/extensions.md §2.2), carve#1007.
     *
     * @param \MarkupCarve\Carve\Node\Document $document The resolved document.
     * @param \MarkupCarve\Carve\Extension\BeforeRenderContext $context The read-only render context.
     *
     * @return \MarkupCarve\Carve\Node\Document
     */
    public function beforeRender(Document $document, BeforeRenderContext $context): Document;
}
