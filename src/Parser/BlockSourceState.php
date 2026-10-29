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
     * Top-level lines the ownership walk opened a code fence on.
     *
     * @var array<int, true>
     */
    public array $fenceOpenerLines = [];

    /**
     * The walked lines with each refused `+` the walk framed lazy read unframed, for the marker classifier.
     *
     * @var array<int, string>
     */
    public array $markerLines = [];

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
     * Attach line numbers for editor scroll synchronization.
     *
     * @var bool
     */
    public bool $trackSourceLines = false;
}
