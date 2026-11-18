<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Resolve;

use MarkupCarve\Carve2\Node;

/**
 * What a resolve found while it walked the tree, handed to the renderer so it need not walk the tree
 * again. Valid only for the tree it was built from, unchanged since.
 */
final class Facts
{
    /**
     * @param \MarkupCarve\Carve2\Node $doc
     * @param array<int, \MarkupCarve\Carve2\Node> $nodes The tree in pre-order without plain text.
     * @param array<int, int> $end The index after the subtree, for the document's blocks and inline footnotes.
     * @param array<int, int> $pos The index of the document's blocks and of inline footnotes, by object id.
     * @param array<int, int> $noteMarks Indexes of footnote references and inline footnotes.
     * @param array<int, int> $headings Indexes of headings.
     * @param array<int, int> $numbered Indexes of caption holders, numbered equations and figure groups.
     * @param array<int, int> $titled Indexes of nodes with a title.
     * @param array<string, true> $taken Authored ids and generated heading ids.
     * @param bool $longDescriptions Whether an image has a long description.
     * @param bool $comments Whether the tree holds a comment.
     * @param array<int, \MarkupCarve\Carve2\Resolve\TableGrid> $tableGrids Grids already built to resolve table spans and alignment.
     */
    public function __construct(
        public readonly Node $doc,
        public readonly array $nodes,
        public readonly array $end,
        public readonly array $pos,
        public readonly array $noteMarks,
        public readonly array $headings,
        public readonly array $numbered,
        public readonly array $titled,
        public readonly array $taken,
        public readonly bool $longDescriptions,
        public readonly bool $comments,
        public readonly array $tableGrids,
    ) {
    }
}
