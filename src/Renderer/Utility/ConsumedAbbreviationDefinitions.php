<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer\Utility;

use MarkupCarve\Carve\Node\Inline\Abbreviation;
use MarkupCarve\Carve\Node\Inline\Text;
use function spl_object_id;

/**
 * How a writer names the definition an emitted expansion came from.
 *
 * PART 11 §10f splits a REFERENCED abbreviation definition by target: Markdown
 * and the canonical writer keep the `*[TERM]: expansion` line, the plain-text
 * and terminal targets drop it and print `TERM (expansion)` at every occurrence
 * instead. The line goes there because the same words would be emitted TWICE,
 * so the clause is explicit that
 *
 *   THE TEST IS WHETHER THIS DEFINITION'S EXPANSION IS EMITTED, not whether
 *   its term appears.
 *
 * DECIDED FROM WHAT WAS EMITTED, never predicted. Both T2 writers record an
 * occurrence here as they emit it and resolve the definition lines afterwards,
 * so no second rule can drift from the first. A structural pass that predicted
 * the answer was tried and got the DEGRADED cases wrong: an occurrence the §25
 * expansion budget refuses, and one a `render.abbreviation` listener replaces,
 * both emit no expansion while looking referenced, and the line - the only other
 * copy of those words - was being dropped anyway. A 50KB definition referenced
 * past the budget lost its second definition's text outright.
 *
 * Recording as-emitted also means the three shapes §10f names need no code of
 * their own. Each simply never records:
 *
 * - THE TERM NEVER APPEARS. No occurrence renders. That is §10a, unchanged.
 * - AN AUTHORED `abbr` OUTRANKS THE DEFINITION (PART 9 §9). The writer's own
 *   `suppressAutomaticAbbreviation` flag returns the visible text and emits no
 *   expansion, so nothing is recorded and the line stays.
 * - A LATER DEFINITION OF THE SAME TERM WON (PART 9R R3, last wins). Only the
 *   winner's expansion is ever emitted, so only the winner's line goes.
 *
 * KEYED ON THE (TERM, EXPANSION) PAIR, not on the term. Keying on the term
 * alone gets the last-wins shape backwards - it would drop `*[A]: a` as well as
 * `*[A]: b` - and would also drop an unreferenced `*[B]: x` merely because some
 * referenced `*[A]: x` happens to expand to the same words.
 */
final class ConsumedAbbreviationDefinitions
{
    /**
     * The separator between a key's two halves.
     *
     * NUL, so it cannot occur in either half: both come from source text that
     * the writers strip control characters out of before emitting.
     *
     * @var string
     */
    private const KEY_SEPARATOR = "\0";

    /**
     * The name a definition and an occurrence agree on.
     */
    public static function key(string $abbr, string $expansion): string
    {
        return $abbr . self::KEY_SEPARATOR . $expansion;
    }

    /**
     * The key an emitted occurrence records itself under.
     */
    public static function keyOf(Abbreviation $node): string
    {
        return self::key(self::termOf($node), $node->getTitle());
    }

    /**
     * The term an occurrence shows, joined from its text descendants.
     *
     * FROM THE NODES rather than from the writer's own rendered string, which
     * carries whatever styling and typography that target applies. The parser
     * gives an `abbreviation` node a single `Text` child holding the matched
     * term; the AST ingest path is not bound to that shape, so the descendants
     * are joined rather than the first child read.
     */
    public static function termOf(Abbreviation $node): string
    {
        $text = '';
        // ITERATIVE, and every object is visited once. Nodes hold a PARENT
        // reference, so the tree is a cyclic graph and an unguarded walk never
        // terminates; recursion would additionally blow the stack on a subtree
        // deep enough to reach the writers' own PART 9 §25 refusal, which has to
        // be what fires instead.
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
