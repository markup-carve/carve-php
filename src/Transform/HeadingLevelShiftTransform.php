<?php

declare(strict_types=1);

namespace Carve\Transform;

use Carve\Node\Block\Heading;
use Carve\Node\Document;
use Carve\Node\Node;
use Carve\Renderer\HtmlRenderer;
use Carve\Renderer\RendererInterface;

/**
 * Returns a transformed copy of a document with shifted heading levels.
 */
class HeadingLevelShiftTransform implements RenderAwareTransformerInterface
{
    public function __construct(protected int $shift = 1)
    {
        $this->shift = max(0, min($shift, 5));
    }

    public function transform(Document $document): Document
    {
        return $this->transformInternal($document, null);
    }

    public function transformForRenderer(Document $document, RendererInterface $renderer): Document
    {
        return $this->transformInternal($document, $renderer);
    }

    protected function transformInternal(Document $document, ?RendererInterface $renderer): Document
    {
        $transformed = clone $document;
        if ($this->shift === 0) {
            return $transformed;
        }

        $preserveSourceLevels = $renderer instanceof HtmlRenderer && $renderer->isRoundTripMode();
        $this->walkAndShift($transformed, $preserveSourceLevels);

        return $transformed;
    }

    protected function walkAndShift(Node $node, bool $preserveSourceLevels): void
    {
        if ($node instanceof Heading) {
            if ($preserveSourceLevels && !$node->hasAttribute('data-djot-source-level')) {
                $node->setAttribute('data-djot-source-level', (string)$node->getLevel());
            }
            $node->setLevel($node->getLevel() + $this->shift);
        }

        foreach ($node->getChildren() as $child) {
            $this->walkAndShift($child, $preserveSourceLevels);
        }
    }
}
