<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;

/**
 * One open container in the ownership walk: its content column in the source
 * (`col`) and where it lands (`canon`).
 *
 * @internal
 */
final class OwnershipFrame
{
    /**
     * @param string $kind
     * @param int $col
     * @param int $canon
     * @param int $cut
     * @param bool $para
     * @param string|null $last
     * @param int|null $markerCol
     * @param int $markerCanon
     * @param string|null $axes
     * @param int $termCut
     * @param (\Closure(string, int): bool)|null $closes
     * @param string|null $span
     * @param bool $absorbs
     * @param bool $lineBlock
     * @param int|null $run
     * @param int|null $reader
     * @param int|null $plus
     * @param int|null $docMarker
     * @param int|null $line
     */
    public function __construct(
        public string $kind,
        public int $col,
        public int $canon,
        public int $cut,
        public bool $para = false,
        public ?string $last = null,
        public ?int $markerCol = null,
        public int $markerCanon = 0,
        public ?string $axes = null,
        public int $termCut = 0,
        public ?Closure $closes = null,
        public ?int $run = null,
        public bool $lineBlock = false,
        public ?string $span = null,
        public bool $absorbs = false,
        public ?int $reader = null,
        public ?int $plus = null,
        public ?int $docMarker = null,
        public ?int $line = null,
    ) {
    }
}
