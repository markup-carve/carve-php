<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Exception;

use RuntimeException;

/**
 * A renderer could not pick in-band sentinels the document does not contain.
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
