<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * Math as the Carve render spells it, which the HTML importer reads back as a typed formula.
 */
final class MathRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        if ($node instanceof MathBlock) {
            return '<p><span class="math display">\\[' . Sink::escape($node->literal) . '\\]</span></p>';
        }

        return '<span class="math inline">\\(' . Sink::escape($node instanceof Math ? $node->getLiteral() : '') . '\\)</span>';
    }
}
