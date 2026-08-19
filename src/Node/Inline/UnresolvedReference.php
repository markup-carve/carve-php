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

    /**
     * The same source, with its COMMENT LINES emptied - what a renderer emits.
     *
     * TWO CONSUMERS, TWO CONTRACTS, SPLIT HERE RATHER THAN IN THE STORED VALUE.
     * PART 12 §3a says `rawRef` is the authored source verbatim, so the
     * canonical writer emits it unchanged and a `%% secret` the author wrote
     * inside a reference label survives a round trip. PART 9 §23 says a comment
     * LINE publishes nothing, so every other target has to empty it again -
     * a renderer that writes the stored string through publishes the author's
     * private text into the output (carve-js found that half; carve-php had
     * the other, losing it in `fmt`).
     *
     * Changing what `rawRef` HOLDS can only ever fix one of the two, which is
     * why the split is at the consumer.
     *
     * ONLY A LINE WHOSE FIRST CHARACTER IS `%` qualifies, the same test the
     * block layer applies: leading whitespace is CONTENT in verse, and a
     * trailing `x %% secret` is an `inline_comment`, which §21 leaves standing.
     */
    public static function renderedSourceOf(Node $node): ?string
    {
        $source = self::sourceOf($node);
        if ($source === null || !str_contains($source, '%%')) {
            return $source;
        }

        $lines = explode("\n", $source);
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, '%%')) {
                $lines[$index] = '';
            }
        }

        return implode("\n", $lines);
    }
}
