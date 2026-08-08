<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Fixture;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\BeforeRenderContext;
use MarkupCarve\Carve\Extension\BeforeRenderExtensionInterface;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Renderer\HtmlRenderer;

/**
 * Clones each heading's inline nodes into a `<nav>` entry, rendering them with
 * the caller's own options. The shape every engine's table-of-contents has.
 */
class CloningNavExtension implements BeforeRenderExtensionInterface
{
    /**
     * @param \MarkupCarve\Carve\CarveConverter $converter The converter registering this extension.
     */
    public function register(CarveConverter $converter): void
    {
    }

    /**
     * @param \MarkupCarve\Carve\Node\Document $document The resolved document.
     * @param \MarkupCarve\Carve\Extension\BeforeRenderContext $context The read-only render context.
     *
     * @return \MarkupCarve\Carve\Node\Document
     */
    public function beforeRender(Document $document, BeforeRenderContext $context): Document
    {
        if (!$context->targetIsHtml()) {
            return $document;
        }

        $entries = '';
        foreach ($document->getChildren() as $node) {
            if (!$node instanceof Heading) {
                continue;
            }
            $id = $node->getAttribute('id');
            if ($id === null) {
                continue;
            }
            // WITH THE CALLER'S OPTIONS. There is no active render to inherit
            // from at `beforeRender` time; without the context this renderer
            // would be built with defaults and the entry would disagree with the
            // heading it was cloned from.
            $renderer = new HtmlRenderer(false, $context->symbols());
            $renderer->setSmartTypography($context->smartTypography());
            $entries .= '<a href="#' . $id . '">'
                . $renderer->renderInlineNodesFragment($node->getChildren())
                . '</a>';
        }

        if ($entries === '') {
            return $document;
        }

        $document->prependChild(new RawBlock('<nav>' . $entries . '</nav>', 'html'));

        return $document;
    }
}
