<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer\Utility;

use MarkupCarve\Carve\Node\Node;

/**
 * Renders a DERIVED DISPLAY TEXT - a run of inline nodes cloned from a heading -
 * with the renderer that is running.
 *
 * PART 9R R4 (DERIVED DISPLAY TEXT CLONES THE SAME NODES,
 * markup-carve/carve#957) hands every consumer NODES rather than a string,
 * precisely so the decisions a renderer owns are still open when it runs: the
 * glyph-or-source-run question, the symbols map, the raw-HTML policy, and how
 * each target spells a code span. Every target therefore needs the same two
 * lines, and three copies of them is how one rule acquires three answers - so
 * they live here.
 *
 * The HTML renderer has its own public `renderInlineNodesFragment()`, which
 * additionally restores the soft-break guards its block path relies on; this
 * trait is for the three text targets, whose `renderNode()` needs no such
 * bracketing.
 */
trait DerivedLabelTrait
{
    /**
     * @param list<\MarkupCarve\Carve\Node\Node> $nodes
     */
    protected function renderDerivedLabel(array $nodes): string
    {
        $output = '';
        foreach ($nodes as $node) {
            $output .= $this->renderNode($node);
        }

        return $output;
    }

    abstract protected function renderNode(Node $node): string;
}
