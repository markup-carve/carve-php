<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\LinkReferenceDefinition;
use MarkupCarve\Carve\Node\Document;

/**
 * @internal
 */
final class DocumentFinalizationPass
{
    public function __construct(private readonly BlockParserState $state)
    {
    }

    public function orderDefinitions(Document $document): void
    {
        $children = $document->getChildren();
        $slots = [];
        $collected = [];
        foreach ($children as $index => $child) {
            if ($child instanceof Footnote || $child instanceof LinkReferenceDefinition) {
                $slots[] = $index;
                $collected[] = [count($slots), $child->getPos()->startOffset ?? PHP_INT_MAX, $child];
            }
        }
        if (count($slots) < 2) {
            return;
        }

        // The collection index keeps equal source positions in document order.
        usort($collected, static fn (array $a, array $b): int => [$a[1], $a[0]] <=> [$b[1], $b[0]]);
        foreach ($slots as $k => $index) {
            $children[$index] = $collected[$k][2];
        }
        $document->setChildren($children);
    }

    public function validateReferences(Closure $addWarning): void
    {
        foreach ($this->state->session->references as $label => $def) {
            if (
                !isset($this->state->session->usedReferences[$label])
                && !str_starts_with($def->url, '#')
                && !str_starts_with($label, '^')
            ) {
                $addWarning("Reference '{$label}' defined but never used", $def->line, 1, false, 'reference', null);
            }
        }
    }

    /**
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param \Closure(\MarkupCarve\Carve\Node\Node, array<string, bool>&): void $collectExplicitIds
     * @param \Closure $addWarning
     */
    public function validateAnchors(Document $document, Closure $collectExplicitIds, Closure $addWarning): void
    {
        if ($this->state->session->anchorLinks === []) {
            return;
        }

        $knownIds = $this->state->session->headingIds;
        $collectExplicitIds($document, $knownIds);
        // Fragment matching follows the case-sensitive IDs emitted in HTML.
        foreach ($this->state->session->anchorLinks as $anchor) {
            if (!isset($knownIds[$anchor['fragment']])) {
                $addWarning(
                    "Broken anchor link '#{$anchor['fragment']}' — no element with this ID exists",
                    $anchor['line'],
                    $anchor['column'],
                    false,
                    'anchor',
                    null,
                );
            }
        }
    }
}
