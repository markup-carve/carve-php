<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use function array_is_list;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function strlen;

/**
 * How many bytes an already-decoded payload actually costs, measured rather
 * than believed.
 *
 * The expansion budgets are sized from the document's source length. On the
 * parse path that number is measured - `BlockParser` takes `strlen($input)` -
 * and an attacker who wants a bigger budget has to send a bigger document for
 * it. On the ingest path the same number arrives INSIDE the payload, as
 * `srcByteLength`, so the payload got to choose the size of the guard that was
 * supposed to bound it, and nine digits turned it off for the price of nine
 * bytes.
 *
 * A cap has to be enforced against something the attacker does not supply. This
 * is that something: the payload has to actually be as big as it claims to be
 * worth, and every byte of it is a byte they had to send.
 *
 * carve-rs's CLI already reasons this way where its library helper did not, and
 * says why in `main.rs`: "The document's own `srcByteLength` cannot stand in for
 * it - that number arrives inside the payload, so a hostile tree can claim 0 and
 * render anything."
 */
final class PayloadSize
{
    /**
     * Bytes of JSON `$payload` would take, near enough to bound a budget with.
     *
     * An approximation on purpose. `json_encode`-ing the payload to call
     * `strlen` on the result would be exact, and would also allocate a second
     * copy of a structure whose size is the very thing in question. This walk
     * allocates nothing but the level it is on, and it UNDERSTATES rather than
     * overstates - escaping and separators are not counted - so the bound it
     * produces is the conservative side of the real cost.
     *
     * Level by level rather than recursively, for the reason `PayloadDepth`
     * gives: a payload deep enough to crash a recursive walk is exactly the
     * payload this is asked about. `$maxDepth` bounds the descent, which also
     * makes the function total on a cyclic array - one that never terminates by
     * running out of tree.
     *
     * @param array<mixed> $payload
     * @param int $maxDepth Levels to descend before stopping.
     */
    public static function bytes(array $payload, int $maxDepth): int
    {
        $total = 0;
        $level = [$payload];
        $depth = 0;

        while ($level !== [] && $depth < $maxDepth) {
            $depth++;
            $next = [];
            foreach ($level as $node) {
                // The pair of brackets around it.
                $total += 2;
                $isList = array_is_list($node);
                foreach ($node as $key => $value) {
                    if (!$isList) {
                        // `"key":` - the quotes and the colon. The comma
                        // between entries is deliberately not counted, which is
                        // where the understatement comes from.
                        $total += strlen((string)$key) + 3;
                    }
                    if (is_array($value)) {
                        $next[] = $value;

                        continue;
                    }
                    if (is_string($value)) {
                        $total += strlen($value) + 2;

                        continue;
                    }
                    if ($value === null || is_bool($value)) {
                        // `null` and `true` are four; `false` is five.
                        $total += 4;

                        continue;
                    }
                    if (is_int($value) || is_float($value)) {
                        $total += strlen((string)$value);

                        continue;
                    }
                    // An object, or a resource: nothing this format can carry,
                    // and the schema refuses it a few lines later. It costs
                    // nothing here rather than being guessed at.
                }
            }
            $level = $next;
        }

        return $total;
    }
}
