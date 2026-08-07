<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use function is_array;

/**
 * How deeply an already-decoded payload nests, measured without recursing.
 *
 * Every ingest surface in this package has two entry points for the same value:
 * one that takes the JSON text and one that takes the array. The string one is
 * bounded for free, because `json_decode` takes a depth argument and refuses
 * past it. The array one is handed a structure somebody else decoded, and the
 * bound has to be re-applied by hand - otherwise the recursive walks below it
 * run until the C stack is gone, which is a segmentation fault rather than a
 * catchable exception. PHP's own recursion limit is not a boundary anything may
 * be defended with.
 *
 * The measurement itself must therefore not recurse either. A check that
 * crashes on the input it exists to refuse is not a check.
 */
final class PayloadDepth
{
    /**
     * Does `$payload` nest strictly less deep than `json_decode`'s `$depth` of
     * `$limit` would allow?
     *
     * `$limit` IS the number a caller passes to `json_decode`, deliberately, so
     * the array path and the string path beside it cannot drift apart: pass the
     * same constant to both and they accept the same set of payloads. PHP reads
     * that argument as an exclusive bound - `json_decode('{}', true, 1)` fails
     * and `json_decode('{}', true, 2)` succeeds - so `$limit` levels of nesting
     * is one too many, and the test here is `>=` rather than `>`.
     *
     * Level by level rather than depth-first: it holds one level of the tree at
     * a time (PHP arrays are copy-on-write, so each entry is a reference rather
     * than a copy), and it returns as soon as one level too many exists, so a
     * 20,000-deep payload costs the first `$limit` levels and not the rest.
     *
     * @param array<mixed> $payload
     * @param int $limit The `json_decode` depth argument this surface's string
     *   entry point uses.
     *
     * @return bool True when the payload fits, false when it must be refused.
     */
    public static function within(array $payload, int $limit): bool
    {
        $level = [$payload];
        $depth = 0;

        while ($level !== []) {
            $depth++;
            if ($depth >= $limit) {
                return false;
            }

            $next = [];
            foreach ($level as $node) {
                foreach ($node as $value) {
                    if (!is_array($value)) {
                        continue;
                    }
                    $next[] = $value;
                }
            }
            $level = $next;
        }

        return true;
    }
}
