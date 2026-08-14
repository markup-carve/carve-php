<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer\Utility;

use MarkupCarve\Carve\Node\Inline\Abbreviation;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use function is_string;
use function spl_object_id;

/**
 * Which abbreviation definitions have their expansion emitted by this render.
 *
 * PART 11 §10f splits a REFERENCED definition by target: Markdown and the
 * canonical writer keep the `*[TERM]: expansion` line, the plain-text and
 * terminal targets drop it and print `TERM (expansion)` at every occurrence
 * instead. The line goes there because the same words would be emitted TWICE,
 * so the clause is explicit that
 *
 *   THE TEST IS WHETHER THIS DEFINITION'S EXPANSION IS EMITTED, not whether
 *   its term appears.
 *
 * That distinction is the whole of this class. Three shapes have a term that
 * appears while the definition's own expansion reaches no target, and in each
 * the definition's text is carried by its line and by nothing else, so dropping
 * it would be exactly the content loss §10a exists to prevent:
 *
 * - THE TERM NEVER APPEARS. No `abbreviation` node exists, nothing is
 *   collected, every line survives. That is §10a, unchanged.
 * - AN AUTHORED `abbr` OUTRANKS THE DEFINITION (PART 9 §9). The resolved
 *   `abbreviation` child contributes only its visible text inside such a span,
 *   so the walk stops counting under it and the definition keeps its line.
 * - A LATER DEFINITION OF THE SAME TERM WON (PART 9R R3, last wins). Only the
 *   winner's expansion is ever emitted, so only the winner's line goes.
 *
 * KEYED ON THE (TERM, EXPANSION) PAIR, not on the term. Keying on the term
 * alone gets the last-wins shape backwards - it would drop `*[A]: a` as well as
 * `*[A]: b` - and would also drop an unreferenced `*[B]: x` merely because some
 * referenced `*[A]: x` happens to expand to the same words.
 *
 * ANSWERED BEFORE ANY BYTE IS WRITTEN, because a definition line can precede
 * the occurrence that consumes it, and both renderers emit the line where the
 * author put it (carve-php#708). A renderer deciding as it goes would have to
 * look ahead anyway.
 */
final class ConsumedAbbreviationDefinitions
{
    /**
     * The separator between a key's two halves.
     *
     * NUL, so it cannot occur in either half: both come from source text that
     * the renderers strip control characters out of before emitting.
     *
     * @var string
     */
    private const KEY_SEPARATOR = "\0";

    /**
     * The set of definitions whose expansion this render emits.
     *
     * @return array<string, true> Keys built by self::key().
     */
    public static function collect(Node $root): array
    {
        $consumed = [];

        // ITERATIVE, and every object is visited once. Nodes hold a PARENT
        // reference, so the tree is a cyclic graph and an unguarded walk never
        // terminates; recursion would additionally blow the stack on a document
        // deep enough to reach the renderers' own PART 9 §25 refusal, which has
        // to be what fires instead.
        $seen = [];
        // Each frame is a node plus whether an authored `abbr` above it has
        // taken over. The flag rides the frame rather than a counter, because a
        // span carrying `abbr` suppresses its whole subtree and nothing below
        // can turn it back on.
        $stack = [[$root, false]];

        while ($stack !== []) {
            /** @var array{0: \MarkupCarve\Carve\Node\Node, 1: bool} $frame */
            $frame = array_pop($stack);
            [$node, $suppressed] = $frame;

            $id = spl_object_id($node);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            if (!$suppressed && $node instanceof Span) {
                $authored = $node->getAttributes()['abbr'] ?? null;
                // Mirrors what both T2 renderers test before they honor an
                // authored value. An empty string is still authored: it wins
                // over the definition and emits nothing, so the definition's
                // expansion reaches no target either way.
                $suppressed = is_string($authored);
            }

            if (!$suppressed && $node instanceof Abbreviation) {
                $consumed[self::key(self::visibleText($node), $node->getTitle())] = true;
            }

            foreach ($node->getChildren() as $child) {
                $stack[] = [$child, $suppressed];
            }
        }

        return $consumed;
    }

    /**
     * The key a definition looks itself up by.
     */
    public static function key(string $abbr, string $expansion): string
    {
        return $abbr . self::KEY_SEPARATOR . $expansion;
    }

    /**
     * The term an occurrence shows, joined from its text descendants.
     *
     * The parser gives an `abbreviation` node a single `Text` child holding the
     * matched term. The AST ingest path is not bound to that shape, so the
     * descendants are joined rather than the first child read.
     */
    private static function visibleText(Abbreviation $node): string
    {
        $text = '';
        $stack = array_reverse($node->getChildren());
        $seen = [];

        while ($stack !== []) {
            $child = array_pop($stack);
            $id = spl_object_id($child);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            if ($child instanceof Text) {
                $text .= $child->getContent();
            }

            foreach (array_reverse($child->getChildren()) as $grandchild) {
                $stack[] = $grandchild;
            }
        }

        return $text;
    }
}
