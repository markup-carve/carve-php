<?php

declare(strict_types=1);

namespace Carve\Transform;

use Carve\Node\Document;
use Carve\Node\Inline\Span;
use Carve\Node\Inline\Text;
use Carve\Node\Node;

/**
 * Rewrites inline footnote spans into explicit parenthetical inline content.
 */
class InlineFootnotesToParenthesesTransform implements TransformerInterface
{
    public function __construct(protected string $cssClass = 'fn')
    {
    }

    public function transform(Document $document): Document
    {
        $transformed = clone $document;
        $this->walk($transformed);

        return $transformed;
    }

    protected function walk(Node $node): void
    {
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Span && $child->hasClass($this->cssClass)) {
                $replacementChildren = array_values([new Text(' ('), ...$child->getChildren(), new Text(')')]);
                $node->replaceChildWithMany($child, $replacementChildren);

                continue;
            }

            $this->walk($child);
        }
    }
}
