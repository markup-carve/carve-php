<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Exception;

use RuntimeException;

/**
 * A renderer reached its recursion ceiling (PART 9 §25).
 *
 * "AT THE RENDER CEILING, A RENDERER REFUSES -- NORMATIVE. Reaching it MUST
 * produce a typed, documented failure naming the depth bound. NOT silent
 * truncation, not a partial document, and not whatever the host language
 * raises when the stack runs out."
 *
 * The ceiling exceeds the parser's own MAX_NESTING_DEPTH by construction, so
 * no tree `parse()` produces can reach it: what arrives here is a tree built
 * through the API or ingested over the wire, where the caller is the one who
 * built it and the one who can act on the failure. Truncating instead returned
 * a document that looked complete and was not.
 */
class RenderDepthExceededException extends RuntimeException
{
    /**
     * @param int $limit The depth bound that was reached.
     * @param string $renderer Short name of the renderer that refused.
     */
    public function __construct(public readonly int $limit, public readonly string $renderer)
    {
        parent::__construct(sprintf(
            'The %s renderer refused a tree deeper than its ceiling of %d levels. '
                . 'A parsed document cannot reach this bound; a tree built through the API or '
                . 'decoded from JSON can. Flatten the nesting before rendering.',
            $renderer,
            $limit,
        ));
    }
}
