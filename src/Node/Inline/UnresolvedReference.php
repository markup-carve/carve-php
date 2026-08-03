<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

use MarkupCarve\Carve\Node\Node;

/**
 * One question, asked in every writer: is this node an UNRESOLVED reference?
 *
 * PART 12 §3a keeps such a reference as a `link` (or `image`) node rather than
 * reverting it to literal source, so the node exists but nothing resolved it:
 * it has no destination, and it carries the verbatim source the author wrote.
 * Every writer renders that source instead of the node - an unresolved
 * reference is never an anchor - so they all need the same predicate, and a
 * copy per writer is how the six of them would drift apart.
 */
final class UnresolvedReference
{
    /**
     * The verbatim authored source (`rawRef`), or null when the node is not an
     * unresolved reference.
     */
    public static function sourceOf(Node $node): ?string
    {
        if ($node instanceof Link && $node->getDestination() === '') {
            return $node->getRawReferenceLabel();
        }
        if ($node instanceof Image && $node->getSource() === '') {
            return $node->getRawReferenceLabel();
        }

        return null;
    }
}
