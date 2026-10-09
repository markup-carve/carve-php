<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use ArrayObject;

/**
 * Escape planning and verification state owned by one canonical writer.
 *
 * @internal
 */
final class CarveWriterState
{
    /**
     * Units consulted during a logged escape pass.
     *
     * @var array<int, true>|null
     */
    public ?array $askedUnits = null;

    /**
     * Cached parse verdicts keyed by candidate bytes.
     *
     * @var array<string, bool>
     */
    public array $candidateVerdicts = [];

    /**
     * Cached AST properties used for canonical comparison.
     *
     * @var array<string, array<string, \ReflectionProperty>>
     */
    public array $canonicalProperties = [];

    /**
     * Units that require conservative escaping.
     *
     * @var array<int, true>|null
     */
    public ?array $escalatedUnits = null;

    /**
     * Escape-call indexes within each unit.
     *
     * @var array<int, int>|null
     */
    public ?array $escapeCallIndexes = null;

    /**
     * Local windows used to verify escape candidates.
     */
    public ?EscapeWindows $escapeWindows = null;

    /**
     * Remaining unit-narrowing probes.
     */
    public int $narrowingBudget = 0;

    /**
     * Remaining occurrence-search probes.
     */
    public int $occurrenceBudget = 0;

    /**
     * Candidate occurrences visited during the current pass.
     *
     * @var \ArrayObject<int, string>|null
     */
    public ?ArrayObject $occurrenceLog = null;

    /**
     * Bracket closers paired with their opening positions.
     *
     * @var array<int, array<int, array{int, int}>>
     */
    public array $pairedClosers = [];

    /**
     * Accumulated cost of escape verification.
     */
    public int $probeCharge = 0;

    /**
     * Occurrences allowed to use minimal escaping.
     *
     * @var array<string, true>|null
     */
    public ?array $relaxedOccurrences = null;

    public int $searchChargeStart = 0;

    public int $searchProbeFloor = 0;

    /**
     * Structural characters that must be escaped.
     *
     * @var array<int, array<int, true>>
     */
    public array $structuralEscapes = [];

    /**
     * @var array<int, array<int, bool>>
     */
    public array $fixedBracketSites = [];

    /**
     * Stable unit numbers for occurrence keys.
     *
     * @var array<int, int>|null
     */
    public ?array $unitNumbers = null;

    /**
     * Cached canonical trees for candidate windows.
     *
     * @var array<string, array{tree: mixed}|null>
     */
    public array $windowTrees = [];
}
