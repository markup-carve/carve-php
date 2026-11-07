<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * Source geometry and tracking options for the current parse.
 *
 * @internal
 */
final class BlockSourceState
{
    /**
     * Line starts in normalized source, keyed by top-level line index.
     *
     * @var array<int, int>
     */
    public array $lineStartOffsets = [];

    /**
     * Unmodified input for source-span offsets.
     */
    public string $originalSource = '';

    /**
     * Normalized top-level lines used to measure block spans.
     *
     * @var array<int, string>
     */
    public array $sourceLines = [];

    /**
     * Nearest list-marker line at or before each source line (-1 for none), built lazily from `$sourceLines`.
     *
     * @var array<int, int>|null
     */
    public ?array $itemMarkerLineIndex = null;

    /**
     * First non-blank line after a marker line, keyed by the marker line.
     *
     * @var array<int, int>
     */
    public array $firstBodyLineAfterMarker = [];

    /**
     * Top-level lines as authored, before the ownership walk moved them;
     * span sites measure against these.
     *
     * @var array<int, string>
     */
    public array $authoredLines = [];

    /**
     * Top-level lines the ownership walk read as lazy paragraph text.
     *
     * @var array<int, true>
     */
    public array $lazyLines = [];

    /**
     * Lines before the block a taken `+` attaches, including a note's lazy continuation.
     *
     * @var array<int, true>
     */
    public array $attachedPreludeLines = [];

    /**
     * @var array<int, int>
     */
    public array $attachedPreludeContentColumns = [];

    /**
     * @var array<int, int>
     */
    public array $commentPayloadHostColumns = [];

    /**
     * Lazy lines the walk folded into an open line block's stanza (carve#4748).
     *
     * @var array<int, true>
     */
    public array $lineBlockLazyLines = [];

    /**
     * Blanks that end a `+` attached code fence with no closer (PART 9 §17 L3).
     *
     * @var array<int, true>
     */
    public array $attachedFenceBlankEnds = [];

    /**
     * Lines without `>` that end a `+` attached quote and resume its host (carve#5427).
     *
     * @var array<int, true>
     */
    public array $attachedQuoteEnds = [];

    /**
     * Lines a refused brace run's paragraph keeps as text, the run included, each to its run's first line (CARVE-P2-035).
     *
     * @var array<int, int>
     */
    public array $refusedKeptLines = [];

    /**
     * Top-level lines the ownership walk opened a code fence on.
     *
     * @var array<int, true>
     */
    public array $fenceOpenerLines = [];

    /**
     * Lines the ownership walk ended an open `%%%` span on because they missed its owner (PART 0 S1),
     * to the span's opener line and delimiter width.
     *
     * @var array<int, array{0: int, 1: int}>
     */
    public array $commentEndLines = [];

    /**
     * Lines the ownership walk closed an open `%%%` span on with its exact-width run.
     *
     * @var array<int, true>
     */
    public array $commentCloserLines = [];

    /**
     * Top-level payload and closer lines of a code or raw fence the ownership walk opened.
     *
     * @var array<int, true>
     */
    public array $fenceBodyLines = [];

    /**
     * Whether the ownership walk read lines 0.. as frontmatter on their raw, unindented spelling.
     */
    public bool $frontmatter = false;

    /**
     * Top-level lines the ownership walk read as closing open containers.
     *
     * @var array<int, true>
     */
    public array $closingLines = [];

    /**
     * @var array<int, true>
     */
    public array $outerQuoteLines = [];

    /**
     * The host of every lone `+` the ownership walk accepted, by line index.
     *
     * @var array<int, array{kind: string, column: int, depth: int, content: int, first: bool, list: int|null, note: int|null}>
     */
    public array $plusHosts = [];

    /**
     * The lint finding of every lone `+` the ownership walk refused, by line index.
     *
     * @var array<int, array{line: int, column: int, host: string, reason: string}|null>
     */
    public array $refusedPlus = [];

    /**
     * Every block opener the ownership walk read past a non-item owner's content column.
     *
     * @var array<int, array{line: int, column: int, kind: string, owner: string, contentColumn: int, authoredColumn: int}>
     */
    public array $overindentedOpeners = [];

    /**
     * Normalized input used to verify computed spans.
     */
    public string $normalizedSource = '';

    /**
     * Whether this source can contain a table row.
     */
    public bool $hasTableDelimiter = false;

    /**
     * Maps byte positions to Unicode codepoint positions.
     */
    public ?PositionIndex $positionIndex = null;

    /**
     * Attach source spans to AST nodes.
     *
     * @var bool
     */
    public bool $trackPositions = false;

    /**
     * Track block spans but no inline source maps; the erasure pass places its nodes by block lines only.
     *
     * @var bool
     */
    public bool $blockPositionsOnly = false;

    /**
     * Attach line numbers for editor scroll synchronization.
     *
     * @var bool
     */
    public bool $trackSourceLines = false;

    /**
     * Whether the walk consumed this line's later `+` of a successive run into a placeholder (carve#5013).
     *
     * @param int $line
     */
    public function isConsumedMarker(int $line): bool
    {
        if (!array_key_exists($line, $this->refusedPlus)) {
            return false;
        }
        $body = ltrim($this->sourceLines[$line] ?? '', " \t");
        while (($rest = ContainerPrefix::quoteContent($body)) !== null) {
            $body = $rest;
        }

        return trim($body, " \t") === '%%';
    }

    /**
     * The marker a follower attaches through: a successive run offers one block, so its placeholders are stepped over.
     *
     * @param int $prior The line right before the follower.
     */
    public function markerLineThrough(int $prior): int
    {
        $at = $prior;
        $crossed = false;
        while ($at > 0 && ($this->isConsumedMarker($at) || isset($this->attachedPreludeLines[$at]))) {
            $crossed = $crossed || $this->isConsumedMarker($at);
            $at--;
        }

        return $crossed ? $at : $prior;
    }

    public function resetItemMarkerIndex(): void
    {
        $this->itemMarkerLineIndex = null;
        $this->firstBodyLineAfterMarker = [];
    }
}
