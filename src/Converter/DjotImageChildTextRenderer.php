<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use MarkupCarve\Carve\Exception\RenderDepthExceededException;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\NonBreakingSpace;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;

final class DjotImageChildTextRenderer extends PlainTextRenderer
{
    protected function renderNode(Node $node): string
    {
        if ($node instanceof NonBreakingSpace || $node instanceof FootnoteRef) {
            return '';
        }
        foreach ($node->getAttributeEntries() as $key => $_value) {
            $node->removeAttribute((string)$key);
        }
        $link = $node instanceof Link && $node->getReferenceLabel() !== null && $node->getDestination() === '';
        $image = $node instanceof Image && $node->getReferenceLabel() !== null && $node->getSource() === '';
        if (!$link && !$image) {
            return parent::renderNode($node);
        }
        if ($this->renderDepth >= self::MAX_RENDER_DEPTH) {
            throw new RenderDepthExceededException(self::MAX_RENDER_DEPTH, 'plain-text');
        }
        $this->renderDepth++;
        try {
            return $node instanceof Image
                ? $this->stripControls($node->getAlt())
                : $this->renderChildren($node);
        } finally {
            $this->renderDepth--;
        }
    }
}
