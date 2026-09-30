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
     * Normalized input used to verify computed spans.
     */
    public string $normalizedSource = '';

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
