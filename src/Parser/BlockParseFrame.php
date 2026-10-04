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
     * Where THIS level's content begins on each source line, in bytes.
     *
     * @var array<int, int>
     */
    public array $currentContentColumns = [];

    /**
     * Comment-fence index for the current line set, built once per line set.
     *
     * A closer must match the opener width EXACTLY, so any later line carrying a
     * fence of that width IS a valid closer: "is there a closer after $i" is
     * exactly "last index for this width > $i". That replaces a per-opener scan
     * to the end of the line set, which is superlinear on input full of openers
     * with DISTINCT widths - the case where a per-width negative cache can never
     * help, because each width is only seen once.
     *
     * @var array<int, int>|null Fence length => LAST index carrying that fence.
     */
    public ?array $commentFenceLastIndex = null;

    /**
     * Source index => next same-width quoted fence before the quote ends.
     *
     * @var array<int, int>|null
     */
    public ?array $blockQuoteCommentCloserIndex = null;

    /**
     * Where a closer of each fence shape LAST occurs in the current line set,
     * built once by fenceCloserIndex().
     *
     * @var array{comment: array<int, int>, colon: array<int, int>, code: array<string, array{runs: array<int, int>, lastAtLeast: array<int, int>}>}|null
     */
    public ?array $fenceCloserIndexCache = null;

    /**
     * Exact column-zero code closers for literal colon-fence bodies.
     *
     * @var array{comment: array<int, int>, colon: array<int, int>, code: array<string, array{runs: array<int, int>, lastAtLeast: array<int, int>}>}|null
     */
    public ?array $literalFenceCloserIndexCache = null;

    public function sourceLineFor(int $index): int
    {
        if ($index < 0) {
            return -1;
        }
        $index += $this->currentLineMapBase;

        return $this->currentLineMap[$index] ?? ($this->currentLineMap === null ? $index : -1);
    }
}
