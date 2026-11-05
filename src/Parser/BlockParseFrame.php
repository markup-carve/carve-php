<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * Source mapping and lookahead caches for one recursive block body.
 *
 * @internal
 */
final class BlockParseFrame
{
    /**
     * Source-line map, shared with unchanged colon bodies.
     *
     * @var array<int, int>|null
     */
    public ?array $currentLineMap = null;

    /**
     * First entry for this body in the shared line map.
     */
    public int $currentLineMapBase = 0;

    public ?ColonFenceIndex $colonFenceIndex = null;

    public int $colonFenceBase = 0;

    /**
     * Colon boundaries for list-hosted continuation divs, shared with their
     * nested bodies while those bodies are the scanned lines unchanged.
     */
    public ?ColonFenceIndex $hostedColonFenceIndex = null;

    public int $hostedColonFenceBase = 0;

    /**
     * Set when the item rebase of a hosted div cannot change these lines: none
     * is walked without eligibility and no authored line, after `quotes` quote
     * markers, starts past `column`.
     *
     * @var array{column: int, quotes: int}|null
     */
    public ?array $inertItemRebase = null;

    public bool $hostedColonFences = false;

    public bool $listHostedColonFences = false;

    public bool $noteHostedColonFences = false;

    /**
     * An item, description or footnote body: a list marker opens a list here (PART 0 S3, carve#4860).
     */
    public bool $markerBody = false;

    /**
     * Where THIS level's content begins on each source line, in bytes.
     *
     * @var array<int, int>
     */
    public array $currentContentColumns = [];

    /**
     * Where a closer of each fence shape LAST occurs in the current line set,
     * built once by fenceCloserIndex().
     *
     * @var array{comment: array<int, int>, colon: array<int, int>, code: array<string, array{runs: array<int, int>, lastAtLeast: array<int, int>}>}|null
     */
    public ?array $fenceCloserIndexCache = null;

    /**
     * Exact column-zero code closers for up to two line sets.
     *
     * @var array<int, array{lines: array<string>, index: array{comment: array<int, int>, colon: array<int, int>, code: array<string, array{runs: array<int, int>, lastAtLeast: array<int, int>}>}|null}>|null
     */
    public ?array $literalFenceCloserIndexCache = null;

    /**
     * Exact code-fence closers grouped by source column and run character.
     *
     * @var array<int, array<string, array{runs: array<int, int>, lastAtLeast: array<int, int>}>>|null
     */
    public ?array $viewCodeClosers = null;

    public ?IndexedFenceView $itemSourceFenceView = null;

    public ?RangeMaximum $itemSourceBoundaryColumns = null;

    /**
     * @var array<int, int>
     */
    public array $itemSourceBoundaryPositions = [];

    public ?IndexedFenceView $literalSourceFenceView = null;

    public ?RangeMaximum $literalSourceBoundaryColumns = null;

    /**
     * @var array<int, int>
     */
    public array $literalSourceBoundaryPositions = [];

    public function sourceLineFor(int $index): int
    {
        if ($index < 0) {
            return -1;
        }
        $index += $this->currentLineMapBase;

        return $this->currentLineMap[$index] ?? ($this->currentLineMap === null ? $index : -1);
    }
}
