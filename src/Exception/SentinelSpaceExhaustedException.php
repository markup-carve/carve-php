<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Exception;

use RuntimeException;

/**
 * A renderer could not pick in-band sentinels the document does not contain.
 *
 * A sentinel exists to be a value the document CANNOT hold. When the private-use
 * area offers no run wide enough, there is no such value, and the mechanism has
 * nothing to offer - so it says so.
 *
 * WHAT THIS REPLACES IS WORSE THAN A REFUSAL. The picker used to fall back to
 * the preferred run, which is a run the document may well contain: the one
 * property the mechanism exists to provide, given up silently, with the damage
 * appearing later as text the author never wrote and no diagnostic anywhere
 * (markup-carve/carve-php#1398). An honest refusal is reviewable; a corrupted
 * document is not.
 *
 * The trigger is adversarial rather than accidental - it needs the private-use
 * area covered with no gap of the requested width, which is roughly a sixth of
 * its 6,400 code points for a run of six and not a document anyone writes by
 * hand. It is reachable all the same, and by exactly the generated input that
 * found the previous defect here (markup-carve/carve-php#1087).
 */
class SentinelSpaceExhaustedException extends RuntimeException
{
    /**
     * @param int $count How many consecutive sentinels were needed.
     * @param int $first The preferred first code point of the run.
     * @param int $last The last code point the search could reach.
     */
    public function __construct(
        public readonly int $count,
        public readonly int $first,
        public readonly int $last,
    ) {
        parent::__construct(sprintf(
            'The renderer refused a document that leaves no run of %d unused private-use '
                . 'code points between U+%04X and U+%04X. Sentinels have to be characters the '
                . 'document cannot contain, and this one contains every candidate. Remove the '
                . 'private-use characters, or render a target that needs no sentinels.',
            $count,
            $first,
            $last,
        ));
    }
}
