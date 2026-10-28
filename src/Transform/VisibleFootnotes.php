<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Transform;

use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\FootnoteRef;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\LabelKey;

/**
 * Find definitions reachable through rendered footnote references.
 *
 * @internal
 */
final class VisibleFootnotes
{
    /**
     * @return array<string, \MarkupCarve\Carve\Node\Block\Footnote>
     */
    public static function definitions(Document $document): array
    {
        $definitions = [];
        foreach ($document->getChildren() as $child) {
            if ($child instanceof Footnote) {
                $definitions[LabelKey::normalize($child->getLabel())] = $child;
            }
        }

        return $definitions;
    }

    /**
     * @param \MarkupCarve\Carve\Node\Node $root
     * @param array<string, \MarkupCarve\Carve\Node\Block\Footnote> $definitions
     *
     * @return array<string, true>
     */
    public static function labels(Node $root, array $definitions): array
    {
        if ($definitions === []) {
            return [];
        }

        $visible = [];
        $pending = [];
        $scan = static function (Node $entry) use ($definitions, &$visible, &$pending): void {
            $stack = [$entry];
            while ($stack !== []) {
                $node = array_pop($stack);
                if ($node instanceof FootnoteRef && !$node->isUnresolved()) {
                    $label = LabelKey::normalize($node->getLabel());
                    if (isset($definitions[$label]) && !isset($visible[$label])) {
                        $visible[$label] = true;
                        $pending[] = $label;
                    }
                }
                $children = $node->getChildren();
                for ($index = count($children) - 1; $index >= 0; $index--) {
                    if (!$children[$index] instanceof Footnote) {
                        $stack[] = $children[$index];
                    }
                }
            }
        };
        $scan($root);
        for ($index = 0; isset($pending[$index]); $index++) {
            $scan($definitions[$pending[$index]]);
        }

        return $visible;
    }
}
