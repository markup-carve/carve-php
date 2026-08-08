<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Fixture;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\BeforeRenderContext;
use MarkupCarve\Carve\Extension\BeforeRenderExtensionInterface;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Node\Document;

/**
 * Emits HTML for its fence, but only where HTML is the render target.
 *
 * The shape a client-script extension has: on the Markdown, plain-text and ANSI
 * targets it must leave the fence alone so that renderer emits the source the
 * author wrote.
 */
class HtmlOnlyFenceExtension implements BeforeRenderExtensionInterface
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

        foreach ($document->getChildren() as $node) {
            if ($node instanceof CodeBlock && $node->getLanguage() === 'myuml') {
                $document->replaceChildNode($node, new RawBlock('<div class="myuml">DIAGRAM</div>', 'html'));
            }
        }

        return $document;
    }
}
