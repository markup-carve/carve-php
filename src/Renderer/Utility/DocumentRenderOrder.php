<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer\Utility;

use MarkupCarve\Carve\Node\Block\LinkReferenceDefinition;
use MarkupCarve\Carve\Node\Document;

final class DocumentRenderOrder
{
    /**
     * @return list<\MarkupCarve\Carve\Node\Node>
     */
    public static function children(Document $document): array
    {
        $blocks = [];
        $definitions = [];
        foreach ($document->getChildren() as $child) {
            $position = $child->getPos();
            if ($child instanceof LinkReferenceDefinition && $position !== null) {
                $definitions[] = ['offset' => $position->startOffset, 'node' => $child];
            } else {
                $blocks[] = $child;
            }
        }
        usort($definitions, static fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);

        $ordered = [];
        $next = 0;
        foreach ($blocks as $block) {
            $position = $block->getPos();
            $start = $position === null ? PHP_INT_MAX : $position->startOffset;
            while (isset($definitions[$next]) && $definitions[$next]['offset'] < $start) {
                $ordered[] = $definitions[$next++]['node'];
            }
            $ordered[] = $block;
        }
        while (isset($definitions[$next])) {
            $ordered[] = $definitions[$next++]['node'];
        }

        return $ordered;
    }
}
