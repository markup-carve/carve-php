<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use ArrayObject;

/**
 * Protected state compatibility for CarveRenderer subclasses.
 *
 * @internal
 */
trait LegacyCarveWriterProperties
{
    /**
     * @var array<int, true>|null
     */
    protected ?array $askedUnits = null;

    /**
     * @var array<string, bool>
     */
    protected array $candidateVerdicts = [];

    /**
     * @var array<string, array<string, \ReflectionProperty>>
     */
    protected array $canonicalProperties = [];

    /**
     * @var array<int, true>|null
     */
    protected ?array $escalatedUnits = null;

    /**
     * @var array<int, int>|null
     */
    protected ?array $escapeCallIndexes = null;

    protected ?EscapeWindows $escapeWindows = null;

    protected int $narrowingBudget = 0;

    protected int $occurrenceBudget = 0;

    /**
     * @var \ArrayObject<int, string>|null
     */
    protected ?ArrayObject $occurrenceLog = null;

    /**
     * @var array<int, array<int, array{int, int}>>
     */
    protected array $pairedClosers = [];

    protected int $probeCharge = 0;

    /**
     * @var array<string, true>|null
     */
    protected ?array $relaxedOccurrences = null;

    protected int $searchChargeStart = 0;

    protected int $searchProbeFloor = 0;

    /**
     * @var array<int, array<int, true>>
     */
    protected array $structuralEscapes = [];

    /**
     * @var array<int, int>|null
     */
    protected ?array $unitNumbers = null;

    /**
     * @var array<string, array{tree: mixed}|null>
     */
    protected array $windowTrees = [];

    private function bindLegacyWriterState(): void
    {
        if (static::class === CarveRenderer::class) {
            return;
        }

        $this->writerState->askedUnits = $this->askedUnits;
        $this->askedUnits =&$this->writerState->askedUnits;
        $this->writerState->candidateVerdicts = $this->candidateVerdicts;
        $this->candidateVerdicts =&$this->writerState->candidateVerdicts;
        $this->writerState->canonicalProperties = $this->canonicalProperties;
        $this->canonicalProperties =&$this->writerState->canonicalProperties;
        $this->writerState->escalatedUnits = $this->escalatedUnits;
        $this->escalatedUnits =&$this->writerState->escalatedUnits;
        $this->writerState->escapeCallIndexes = $this->escapeCallIndexes;
        $this->escapeCallIndexes =&$this->writerState->escapeCallIndexes;
        $this->writerState->escapeWindows = $this->escapeWindows;
        $this->escapeWindows =&$this->writerState->escapeWindows;
        $this->writerState->narrowingBudget = $this->narrowingBudget;
        $this->narrowingBudget =&$this->writerState->narrowingBudget;
        $this->writerState->occurrenceBudget = $this->occurrenceBudget;
        $this->occurrenceBudget =&$this->writerState->occurrenceBudget;
        $this->writerState->occurrenceLog = $this->occurrenceLog;
        $this->occurrenceLog =&$this->writerState->occurrenceLog;
        $this->writerState->pairedClosers = $this->pairedClosers;
        $this->pairedClosers =&$this->writerState->pairedClosers;
        $this->writerState->probeCharge = $this->probeCharge;
        $this->probeCharge =&$this->writerState->probeCharge;
        $this->writerState->relaxedOccurrences = $this->relaxedOccurrences;
        $this->relaxedOccurrences =&$this->writerState->relaxedOccurrences;
        $this->writerState->searchChargeStart = $this->searchChargeStart;
        $this->searchChargeStart =&$this->writerState->searchChargeStart;
        $this->writerState->searchProbeFloor = $this->searchProbeFloor;
        $this->searchProbeFloor =&$this->writerState->searchProbeFloor;
        $this->writerState->structuralEscapes = $this->structuralEscapes;
        $this->structuralEscapes =&$this->writerState->structuralEscapes;
        $this->writerState->unitNumbers = $this->unitNumbers;
        $this->unitNumbers =&$this->writerState->unitNumbers;
        $this->writerState->windowTrees = $this->windowTrees;
        $this->windowTrees =&$this->writerState->windowTrees;
    }
}
