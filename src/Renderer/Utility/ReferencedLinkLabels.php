<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer\Utility;

use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\Link;

final class ReferencedLinkLabels
{
    /**
     * @return array<string, true>
     */
    public static function collect(Node $root): array
    {
        $labels = [];
        $stack = [$root];
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($node instanceof Link || $node instanceof Image) {
                $label = $node->getReferenceLabel();
                if ($label !== null && !($node instanceof Link && $node->isFromHeadingReference())) {
                    $labels[$label] = true;
                }
            }
            foreach ($node->getChildren() as $child) {
                $stack[] = $child;
            }
        }

        return $labels;
    }
}
