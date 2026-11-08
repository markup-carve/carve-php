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
     * First frame of the run of plain frames this one belongs to, null when it starts one.
     */
    public ?OwnershipFrame $runHead = null;

    /**
     * On a run head: the stack index of the last frame pushed onto its run.
     */
    public int $runEnd = 0;

    /**
     * A `+` attached fence or colon block with no reachable closer ends at its first blank (PART 9 §17 L3).
     */
    public bool $endsAtBlank = false;

    /**
     * Whether a `+` attached this code fence, so a `+` inside it is payload (PART 9 §17 L3).
     */
    public bool $plusAttached = false;

    /**
     * A marker-line attachment can move its opener outside the host's content column.
     * Keep that host for the trace when the attached container ends at a blank.
     *
     * @var list<\MarkupCarve\Carve\Parser\OwnershipFrame>|null
     */
    public ?array $blankHostFrames = null;

    /**
     * On an item: a term or description in it closed and no block opened in the item since (PART 0 S4, carve#5320).
     */
    public bool $descriptionLeft = false;

    /**
     * A definition term opened inside this item, including a term whose list has since ended.
     */
    public bool $hasDefinition = false;

    /**
     * On a note: a losing duplicate whose lone `+` took nothing; the frame closes at the next line (carve#5474).
     */
    public bool $losing = false;

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
     * @param int|null $listCol The marker column of the list's first item.
     * @param (\Closure(string, int): bool)|null $closes
     * @param string|null $span
     * @param bool $lineBlock
     * @param int|null $run
     * @param int|null $reader
     * @param int|null $plus
     * @param int|null $docMarker
     * @param int|null $line
     * @param int|null $tableLine
     * @param string|null $family
     * @param int|null $opened
     * @param int|null $written
     * @param bool $task
     * @param int $c5Shift
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
        public ?int $listCol = null,
        public ?Closure $closes = null,
        public ?int $run = null,
        public bool $lineBlock = false,
        public ?string $span = null,
        public ?int $reader = null,
        public ?int $plus = null,
        public ?int $docMarker = null,
        public ?int $line = null,
        public ?int $tableLine = null,
        public ?string $family = null,
        public ?int $opened = null,
        public ?int $written = null,
        public bool $task = false,
        public int $c5Shift = 0,
    ) {
    }
}
