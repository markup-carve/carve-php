<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use ArrayObject;
use Closure;
use MarkupCarve\Carve\Node\Document;

/**
 * Verifies and narrows structural escaping against parsed output.
 *
 * @internal
 */
final class CanonicalEscapeSearch
{
    /**
     * @param \MarkupCarve\Carve\Renderer\CarveWriterState $state
     * @param \Closure(string): (array{tree: mixed}|null) $canonicalTreeCallback
     * @param \Closure(\MarkupCarve\Carve\Node\Document): (array<int, \MarkupCarve\Carve\Node\Node>) $collectEscapeUnitsCallback
     * @param \Closure(): (array<int, string>) $loggedOccurrencesCallback
     * @param \Closure(\MarkupCarve\Carve\Node\Document, string): (string) $renderOnePassCallback
     * @param \Closure(\MarkupCarve\Carve\Node\Document, string): (string) $renderWithEscapeModeCallback
     * @param \Closure(): (array<int, true>) $takeAskedUnitsCallback
     * @param (\Closure(string, array{tree: mixed}): (bool))|null $candidateHoldsCallback
     * @param (\Closure(string): (string))|null $candidateKeyCallback
     * @param (\Closure(\MarkupCarve\Carve\Node\Document, array<int, \MarkupCarve\Carve\Node\Node>, array{tree: mixed}, int, string): (void))|null $narrowOccurrencesCallback
     * @param (\Closure(\MarkupCarve\Carve\Node\Document, bool, array<int, \MarkupCarve\Carve\Node\Node>, \Closure(): void, \Closure(): void, array{tree: mixed}, int): (bool))|null $probeKeepsCallback
     * @param (\Closure(\MarkupCarve\Carve\Node\Document, array<int, \MarkupCarve\Carve\Node\Node>, array<int, string>, array{tree: mixed}, int, bool): (void))|null $relaxOccurrencesCallback
     * @param (\Closure(\MarkupCarve\Carve\Node\Document, array<int, \MarkupCarve\Carve\Node\Node>, array{tree: mixed}, int, bool): (void))|null $relaxUnitsCallback
     * @param (\Closure(\MarkupCarve\Carve\Node\Document): (string))|null $renderSelectivelyCallback
     * @param (\Closure(array<int, array{owner: \MarkupCarve\Carve\Node\Node, lo: int, hi: int}>): (?string))|null $renderWindowCallback
     * @param (\Closure(int, int): (bool))|null $searchIsSpentCallback
     * @param (\Closure(\MarkupCarve\Carve\Node\Document, array<int, \MarkupCarve\Carve\Node\Node>, array<int, string>, array{tree: mixed}, int, bool): (string))|null $searchOccurrencesCallback
     * @param (\Closure(\MarkupCarve\Carve\Node\Document, array<int, \MarkupCarve\Carve\Node\Node>, array<int, \MarkupCarve\Carve\Node\Node>, array{tree: mixed}, int, bool): (string))|null $searchUnitsCallback
     * @param (\Closure(string): (array{tree: mixed}|null))|null $windowTreeCallback
     */
    public function __construct(
        private CarveWriterState $state,
        private Closure $canonicalTreeCallback,
        private Closure $collectEscapeUnitsCallback,
        private Closure $loggedOccurrencesCallback,
        private Closure $renderOnePassCallback,
        private Closure $renderWithEscapeModeCallback,
        private Closure $takeAskedUnitsCallback,
        private ?Closure $candidateHoldsCallback = null,
        private ?Closure $candidateKeyCallback = null,
        private ?Closure $narrowOccurrencesCallback = null,
        private ?Closure $probeKeepsCallback = null,
        private ?Closure $relaxOccurrencesCallback = null,
        private ?Closure $relaxUnitsCallback = null,
        private ?Closure $renderSelectivelyCallback = null,
        private ?Closure $renderWindowCallback = null,
        private ?Closure $searchIsSpentCallback = null,
        private ?Closure $searchOccurrencesCallback = null,
        private ?Closure $searchUnitsCallback = null,
        private ?Closure $windowTreeCallback = null,
    ) {
    }

    /**
     * The conservative form of the units that need it, and the minimal form of
     * every other unit (PART 11 section 2b).
     */
    public function narrowEscalation(Document $document, string $conservative, ?string $minimal = null): string
    {
        $conservativeTree = $this->canonicalTree($conservative);
        // Null answers "cannot tell", exactly as it does for the minimal form:
        // with no tree to hold the narrowing against, there is nothing to
        // narrow toward.
        if ($conservativeTree === null) {
            return $conservative;
        }

        $all = $this->collectEscapeUnits($document);
        if ($all === []) {
            return $conservative;
        }

        $escalated = [];
        foreach ($all as $unit) {
            $escalated[spl_object_id($unit)] = true;
        }
        $this->state->escalatedUnits = $escalated;
        $this->state->candidateVerdicts = [$this->callCandidateKey($conservative) => true];
        if ($minimal !== null) {
            // render() narrows only after the minimal form failed to hold.
            $this->state->candidateVerdicts[$this->callCandidateKey($minimal)] = false;
        }

        try {
            // THE CONTROL RENDER LOGS WHICH UNITS THE WRITER ACTUALLY ASKS
            // ABOUT, so the search below can skip the ones it cannot move.
            // collectEscapeUnits() is a generic walk over every node that COULD
            // carry an escaped character; the units that DO are whatever the
            // writer's own escape arms charge a character to, and only those
            // read $escalatedUnits. A unit the writer never asks about renders
            // the same bytes in or out of the set, so offering it its minimal
            // form is a render and a parse spent to learn nothing.
            //
            // Deep nesting produces many units the writer never asks about.
            // Probing each would re-render and re-parse the expanding output.
            //
            // Logging it rather than predicting it is the same choice
            // collectEscapeUnits() makes and for the same reason: the set is
            // whatever the arms visit, so an arm that grows a new escape cannot
            // fall out of the search. And a unit wrongly left out cannot
            // produce wrong output - every state the search returns is
            // re-parsed against $conservativeTree, exactly as before.
            $this->state->askedUnits = [];
            try {
                $best = $this->callRenderSelectively($document);
            } finally {
                $asked = $this->takeAskedUnits();
            }
            if ($best !== $conservative) {
                return $conservative;
            }
            $units = [];
            foreach ($all as $unit) {
                if (isset($asked[spl_object_id($unit)])) {
                    $units[] = $unit;
                }
            }
            // No guard for an EMPTY $units: relaxUnits() returns on an empty
            // group, and a check here would be one no corpus document can
            // reach - the control render asks about a unit for every byte the
            // two forms differ in, and they differ or this is not running.
            //
            // Probes render only a window around the relaxed units; the state
            // the windowed search settles on is verified against the whole
            // document, and the search is redone with whole-document probes
            // when it does not hold.
            $best = $this->callSearchUnits($document, $all, $units, $conservativeTree, strlen($conservative), true);
            if (!$this->callCandidateHolds($best, $conservativeTree)) {
                $best = $this->callSearchUnits($document, $all, $units, $conservativeTree, strlen($conservative), false);
            }
            // PART 11 section 2 TAKES THE DECISION PER OPENER OCCURRENCE, and
            // a unit is still ONE KNOB: a unit that fails is written
            // conservatively IN FULL, so every candidate character beside the
            // one that needed it is escaped for nothing. Section 2b bounds how
            // far the fallback reaches; this is what is left inside the bound
            // (markup-carve/carve#1533).
            $this->callNarrowOccurrences($document, $units, $conservativeTree, strlen($conservative), $best);

            return $best;
        } finally {
            $this->state->escalatedUnits = null;
            $this->state->candidateVerdicts = [];
            $this->state->escapeWindows = null;
            $this->state->windowTrees = [];
        }
    }

    /**
     * The unit search from a fully escalated state, returning the render of
     * the state it settles on.
     *
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param array<int, \MarkupCarve\Carve\Node\Node> $all
     * @param array<int, \MarkupCarve\Carve\Node\Node> $units
     * @param array{tree: mixed} $conservativeTree
     * @param int $conservativeLength
     * @param bool $local
     */
    public function searchUnits(
        Document $document,
        array $all,
        array $units,
        array $conservativeTree,
        int $conservativeLength,
        bool $local,
    ): string {
        $this->state->escalatedUnits = [];
        foreach ($all as $unit) {
            $this->state->escalatedUnits[spl_object_id($unit)] = true;
        }
        // Eight times the depth of the halving, which is what narrowing four
        // independent failing units costs.
        $this->state->narrowingBudget = 8 * (int)ceil(log(count($units) + 1, 2)) + 8;
        $this->state->searchChargeStart = $this->state->probeCharge;
        $this->state->searchProbeFloor = -(CarveWriterGrammar::ESCAPE_SEARCH_PROBE_FACTOR - 1) * $this->state->narrowingBudget;
        $this->callRelaxUnits($document, $units, $conservativeTree, $conservativeLength, $local);

        return $this->callRenderSelectively($document);
    }

    /**
     * Apply a relaxation and keep it when the tree still holds, undo it
     * otherwise.
     *
     * With `$local`, the probe renders and re-parses only the window around
     * `$units` (EscapeWindows) and compares that window before and after.
     *
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param bool $local
     * @param array<int, \MarkupCarve\Carve\Node\Node> $units
     * @param \Closure(): void $apply
     * @param \Closure(): void $undo
     * @param array{tree: mixed} $conservativeTree
     * @param int $conservativeLength
     */
    public function probeKeeps(
        Document $document,
        bool $local,
        array $units,
        Closure $apply,
        Closure $undo,
        array $conservativeTree,
        int $conservativeLength,
    ): bool {
        $window = null;
        if ($local) {
            $this->state->escapeWindows ??= new EscapeWindows($document);
            $window = $this->state->escapeWindows->windowFor($units);
        }
        $before = $window === null ? null : $this->callRenderWindow($window);
        // A window near the document's size saves nothing over the whole-document probe.
        $beforeTree = $before === null || strlen($before) * 2 > $conservativeLength ? null : $this->callWindowTree($before);
        $apply();
        if ($window !== null && $beforeTree !== null) {
            $after = $this->callRenderWindow($window);
            if ($after !== null) {
                $this->state->probeCharge += strlen((string)$before) + strlen($after);
                // Loose, as in candidateHolds().
                if ($this->callWindowTree($after) == $beforeTree) {
                    return true;
                }
                $undo();

                return false;
            }
        }
        $candidate = $this->callRenderSelectively($document);
        $this->state->probeCharge += strlen($candidate);
        if ($this->callCandidateHolds($candidate, $conservativeTree)) {
            return true;
        }
        $undo();

        return false;
    }

    /**
     * Whether a search may not probe again: its count is spent, and so is
     * either its parse allowance or its cap on extra probes.
     */
    public function searchIsSpent(int $budget, int $conservativeLength): bool
    {
        return $budget <= 0 && (
            $this->state->probeCharge - $this->state->searchChargeStart >= CarveWriterGrammar::ESCAPE_SEARCH_PARSE_FACTOR * $conservativeLength
            || $budget <= $this->state->searchProbeFloor
        );
    }

    /**
     * @return array{tree: mixed}|null
     */
    public function windowTree(string $source): ?array
    {
        $key = $this->callCandidateKey($source);
        if (!array_key_exists($key, $this->state->windowTrees)) {
            $this->state->windowTrees[$key] = $this->canonicalTree($source);
        }

        return $this->state->windowTrees[$key];
    }

    /**
     * @param array<int, array{owner: \MarkupCarve\Carve\Node\Node, lo: int, hi: int}> $window
     */
    public function renderWindow(array $window): ?string
    {
        assert($this->state->escapeWindows !== null);
        $rendered = $this->state->escapeWindows->renderPruned(
            $window,
            fn (): string => $this->renderOnePass($this->state->escapeWindows->document(), CarveWriterGrammar::ESCAPE_MODE_CONSERVATIVE),
        );

        // A window that opens on a break would re-parse as frontmatter.
        return $rendered === null || str_starts_with($rendered, '---') ? null : $rendered;
    }

    /**
     * Whether `$candidate` re-parses to `$conservativeTree`, parsing each
     * distinct candidate once per narrowing.
     *
     * @param string $candidate
     * @param array{tree: mixed} $conservativeTree
     */
    public function candidateHolds(string $candidate, array $conservativeTree): bool
    {
        $key = $this->callCandidateKey($candidate);
        if (!isset($this->state->candidateVerdicts[$key])) {
            $candidateTree = $this->canonicalTree($candidate);
            // Loose, because escapingIsRedundant() compares the same trees the
            // same way: two spellings of one document differ in field ORDER,
            // not in content.
            $this->state->candidateVerdicts[$key] = $candidateTree !== null && $candidateTree == $conservativeTree;
        }

        return $this->state->candidateVerdicts[$key];
    }

    public function candidateKey(string $candidate): string
    {
        return strlen($candidate) . ':' . hash('xxh128', $candidate);
    }

    /**
     * Hand `$units` their minimal form where the document still holds, halving
     * the group on failure.
     *
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param array<int, \MarkupCarve\Carve\Node\Node> $units
     * @param array{tree: mixed} $conservativeTree
     * @param int $conservativeLength
     * @param bool $local
     */
    public function relaxUnits(
        Document $document,
        array $units,
        array $conservativeTree,
        int $conservativeLength,
        bool $local,
    ): void {
        $count = count($units);
        if ($count === 0 || $this->callSearchIsSpent($this->state->narrowingBudget, $conservativeLength)) {
            return;
        }
        $this->state->narrowingBudget--;
        $kept = $this->callProbeKeeps(
            $document,
            $local,
            $units,
            function () use ($units): void {
                foreach ($units as $unit) {
                    unset($this->state->escalatedUnits[spl_object_id($unit)]);
                }
            },
            function () use ($units): void {
                foreach ($units as $unit) {
                    $this->state->escalatedUnits[spl_object_id($unit)] = true;
                }
            },
            $conservativeTree,
            $conservativeLength,
        );
        if ($kept || $count === 1) {
            return;
        }
        $half = intdiv($count, 2);
        $this->callRelaxUnits($document, array_slice($units, 0, $half), $conservativeTree, $conservativeLength, $local);
        $this->callRelaxUnits($document, array_slice($units, $half), $conservativeTree, $conservativeLength, $local);
    }

    /**
     * The candidate escapes an escalated unit can still hand back, one
     * occurrence at a time (PART 11 section 2).
     *
     * SAME SEARCH, ONE LEVEL FINER. The comparison is still document-scoped,
     * so a failure still reports THAT the document changed and never WHERE;
     * the occurrence is found by trying, and every state kept is one that
     * re-parsed to the tree the conservative form parses to.
     *
     * THE OCCURRENCES ARE LOGGED, NOT PREDICTED. A candidate site is whatever
     * the writer's own escape arms visit, so they are collected by rendering
     * once with the log switched on rather than by a second enumeration here
     * that could drift from the one that emits. A key is `unit:ordinal` within
     * the unit, which is stable across the search because relaxing one
     * occurrence changes the bytes and not the sites: the arms walk the node's
     * own text, which no relaxation touches.
     *
     * THE FIRST RENDER IS A CONTROL, as it is one level up. With nothing
     * relaxed it must reproduce the state the unit search settled on byte for
     * byte; if logging changed what was written, the unit-scoped answer stands
     * rather than a narrowing built on a pass that is not the pass being
     * measured.
     *
     * BOUNDED THE SAME WAY AND FOR THE SAME REASON. A group holding no failing
     * occurrence is relaxed in one render, so a document with a handful of them
     * costs about log(n) renders - but a document where every occurrence is
     * load bearing drives the halving to its leaves and pays a render and a
     * parse per occurrence, which is a render of the whole document per escaped
     * character. A paragraph of indented table rows is exactly that, and it is
     * ordinary input rather than an adversarial one. The OUTPUT is unchanged
     * where the budget binds: those occurrences are the opener runs section 2
     * requires escaped in full.
     *
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param array<int, \MarkupCarve\Carve\Node\Node> $units
     * @param array{tree: mixed} $conservativeTree
     * @param int $conservativeLength
     * @param string $best
     */
    public function narrowOccurrences(
        Document $document,
        array $units,
        array $conservativeTree,
        int $conservativeLength,
        string &$best,
    ): void {
        $numbers = [];
        foreach (array_values($units) as $index => $unit) {
            $numbers[spl_object_id($unit)] = $index;
        }
        $unitScoped = $best;

        $this->state->unitNumbers = $numbers;
        $this->state->relaxedOccurrences = [];
        $this->state->occurrenceLog = new ArrayObject();
        try {
            $control = $this->callRenderSelectively($document);
            $occurrences = $this->loggedOccurrences();
            $this->state->occurrenceLog = null;
            if ($control !== $unitScoped || $occurrences === []) {
                return;
            }

            // OFFERED FROM THE END OF THE DOCUMENT BACKWARDS, which is what
            // makes the escape that survives the OPENER's. Section 2 asks
            // whether omitting the escapes on an occurrence would let the
            // construct FORM, and a construct forms at its opener - so with the
            // opener still escaped every later candidate on the same line is
            // free, while relaxing the opener first leaves the escape on a
            // closer that was never load bearing (`{.note \}` where section 2
            // wants `\{.note}`). Both spellings re-parse to the same tree, so
            // only the order separates them.
            $order = array_reverse($occurrences);
            $units = array_values($units);
            $candidate = $this->callSearchOccurrences($document, $units, $order, $conservativeTree, $conservativeLength, true);
            if (!$this->callCandidateHolds($candidate, $conservativeTree)) {
                $candidate = $this->callSearchOccurrences($document, $units, $order, $conservativeTree, $conservativeLength, false);
            }
            $best = $candidate;
        } finally {
            $this->state->unitNumbers = null;
            $this->state->relaxedOccurrences = null;
            $this->state->occurrenceLog = null;
            $this->state->escapeCallIndexes = null;
        }
    }

    /**
     * The occurrence search from a state with nothing relaxed, returning the
     * render of the state it settles on.
     *
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param array<int, \MarkupCarve\Carve\Node\Node> $units
     * @param array<int, string> $order
     * @param array{tree: mixed} $conservativeTree
     * @param int $conservativeLength
     * @param bool $local
     */
    public function searchOccurrences(
        Document $document,
        array $units,
        array $order,
        array $conservativeTree,
        int $conservativeLength,
        bool $local,
    ): string {
        $this->state->relaxedOccurrences = [];
        $this->state->occurrenceBudget = 8 * (int)ceil(log(count($order) + 1, 2)) + 8;
        $this->state->searchChargeStart = $this->state->probeCharge;
        $this->state->searchProbeFloor = -(CarveWriterGrammar::ESCAPE_SEARCH_PROBE_FACTOR - 1) * $this->state->occurrenceBudget;
        $this->callRelaxOccurrences($document, $units, $order, $conservativeTree, $conservativeLength, $local);
        // AND THEN ONE SWEEP OF WHAT IS LEFT, because the halving is not a
        // FIXPOINT. Relaxing occurrences is not monotone: an occurrence
        // rejected while a neighbour was still escaped can be free once
        // that neighbour is relaxed, and the halving never revisits a group
        // it has descended past. Corpus 160 is the case - the closing
        // `:::` line cannot go bare while the OPENING one is escaped,
        // because then it is the only fence marker on the page, and it can
        // once the opener is bare. The sweep offers every still-escalated
        // occurrence once more, on top of everything the halving accepted,
        // and spends the same budget - so where the budget is already gone
        // it costs nothing, which is the pathological document.
        foreach ($order as $key) {
            if ($this->callSearchIsSpent($this->state->occurrenceBudget, $conservativeLength)) {
                break;
            }
            if (isset($this->state->relaxedOccurrences[$key])) {
                continue;
            }
            $this->callRelaxOccurrences($document, $units, [$key], $conservativeTree, $conservativeLength, $local);
        }

        return $this->callRenderSelectively($document);
    }

    /**
     * Hand `$group` its bare form where the document still holds, halving the
     * group on failure.
     *
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param array<int, \MarkupCarve\Carve\Node\Node> $units
     * @param array<int, string> $group
     * @param array{tree: mixed} $conservativeTree
     * @param int $conservativeLength
     * @param bool $local
     */
    public function relaxOccurrences(
        Document $document,
        array $units,
        array $group,
        array $conservativeTree,
        int $conservativeLength,
        bool $local,
    ): void {
        $count = count($group);
        if ($count === 0 || $this->callSearchIsSpent($this->state->occurrenceBudget, $conservativeLength)) {
            return;
        }
        $this->state->occurrenceBudget--;
        $owners = [];
        foreach ($group as $key) {
            $unit = $units[(int)strstr($key, ':', true)];
            $owners[spl_object_id($unit)] = $unit;
        }
        $kept = $this->callProbeKeeps(
            $document,
            $local,
            array_values($owners),
            function () use ($group): void {
                foreach ($group as $key) {
                    $this->state->relaxedOccurrences[$key] = true;
                }
            },
            function () use ($group): void {
                foreach ($group as $key) {
                    unset($this->state->relaxedOccurrences[$key]);
                }
            },
            $conservativeTree,
            $conservativeLength,
        );
        if ($kept || $count === 1) {
            return;
        }
        $half = intdiv($count, 2);
        $this->callRelaxOccurrences($document, $units, array_slice($group, 0, $half), $conservativeTree, $conservativeLength, $local);
        $this->callRelaxOccurrences($document, $units, array_slice($group, $half), $conservativeTree, $conservativeLength, $local);
    }

    public function renderSelectively(Document $document): string
    {
        return $this->renderWithEscapeMode($document, CarveWriterGrammar::ESCAPE_MODE_CONSERVATIVE);
    }

    /**
     * @return array{tree: mixed}|null
     */
    private function canonicalTree(string $source): ?array
    {
        return ($this->canonicalTreeCallback)($source);
    }

    /**
     * @return array<int, \MarkupCarve\Carve\Node\Node>
     */
    private function collectEscapeUnits(Document $document): array
    {
        return ($this->collectEscapeUnitsCallback)($document);
    }

    /**
     * @return array<int, string>
     */
    private function loggedOccurrences(): array
    {
        return ($this->loggedOccurrencesCallback)();
    }

    private function renderOnePass(Document $document, string $escapeMode): string
    {
        return ($this->renderOnePassCallback)($document, $escapeMode);
    }

    private function renderWithEscapeMode(Document $document, string $escapeMode): string
    {
        return ($this->renderWithEscapeModeCallback)($document, $escapeMode);
    }

    /**
     * @return array<int, true>
     */
    private function takeAskedUnits(): array
    {
        return ($this->takeAskedUnitsCallback)();
    }

    /**
     * @param string $candidate
     * @param array{tree: mixed} $conservativeTree
     */
    private function callCandidateHolds(string $candidate, array $conservativeTree): bool
    {
        if ($this->candidateHoldsCallback !== null) {
            return ($this->candidateHoldsCallback)($candidate, $conservativeTree);
        }

        return $this->candidateHolds($candidate, $conservativeTree);
    }

    private function callCandidateKey(string $candidate): string
    {
        if ($this->candidateKeyCallback !== null) {
            return ($this->candidateKeyCallback)($candidate);
        }

        return $this->candidateKey($candidate);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param array<int, \MarkupCarve\Carve\Node\Node> $units
     * @param array{tree: mixed} $conservativeTree
     * @param int $conservativeLength
     * @param string $best
     */
    private function callNarrowOccurrences(
        Document $document,
        array $units,
        array $conservativeTree,
        int $conservativeLength,
        string &$best,
    ): void {
        if ($this->narrowOccurrencesCallback !== null) {
            ($this->narrowOccurrencesCallback)($document, $units, $conservativeTree, $conservativeLength, $best);

            return;
        }

        $this->narrowOccurrences($document, $units, $conservativeTree, $conservativeLength, $best);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param bool $local
     * @param array<int, \MarkupCarve\Carve\Node\Node> $units
     * @param \Closure(): void $apply
     * @param \Closure(): void $undo
     * @param array{tree: mixed} $conservativeTree
     * @param int $conservativeLength
     */
    private function callProbeKeeps(
        Document $document,
        bool $local,
        array $units,
        Closure $apply,
        Closure $undo,
        array $conservativeTree,
        int $conservativeLength,
    ): bool {
        if ($this->probeKeepsCallback !== null) {
            return ($this->probeKeepsCallback)($document, $local, $units, $apply, $undo, $conservativeTree, $conservativeLength);
        }

        return $this->probeKeeps($document, $local, $units, $apply, $undo, $conservativeTree, $conservativeLength);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param array<int, \MarkupCarve\Carve\Node\Node> $units
     * @param array<int, string> $group
     * @param array{tree: mixed} $conservativeTree
     * @param int $conservativeLength
     * @param bool $local
     */
    private function callRelaxOccurrences(
        Document $document,
        array $units,
        array $group,
        array $conservativeTree,
        int $conservativeLength,
        bool $local,
    ): void {
        if ($this->relaxOccurrencesCallback !== null) {
            ($this->relaxOccurrencesCallback)($document, $units, $group, $conservativeTree, $conservativeLength, $local);

            return;
        }

        $this->relaxOccurrences($document, $units, $group, $conservativeTree, $conservativeLength, $local);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param array<int, \MarkupCarve\Carve\Node\Node> $units
     * @param array{tree: mixed} $conservativeTree
     * @param int $conservativeLength
     * @param bool $local
     */
    private function callRelaxUnits(
        Document $document,
        array $units,
        array $conservativeTree,
        int $conservativeLength,
        bool $local,
    ): void {
        if ($this->relaxUnitsCallback !== null) {
            ($this->relaxUnitsCallback)($document, $units, $conservativeTree, $conservativeLength, $local);

            return;
        }

        $this->relaxUnits($document, $units, $conservativeTree, $conservativeLength, $local);
    }

    private function callRenderSelectively(Document $document): string
    {
        if ($this->renderSelectivelyCallback !== null) {
            return ($this->renderSelectivelyCallback)($document);
        }

        return $this->renderSelectively($document);
    }

    /**
     * @param array<int, array{owner: \MarkupCarve\Carve\Node\Node, lo: int, hi: int}> $window
     */
    private function callRenderWindow(array $window): ?string
    {
        if ($this->renderWindowCallback !== null) {
            return ($this->renderWindowCallback)($window);
        }

        return $this->renderWindow($window);
    }

    private function callSearchIsSpent(int $budget, int $conservativeLength): bool
    {
        if ($this->searchIsSpentCallback !== null) {
            return ($this->searchIsSpentCallback)($budget, $conservativeLength);
        }

        return $this->searchIsSpent($budget, $conservativeLength);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param array<int, \MarkupCarve\Carve\Node\Node> $units
     * @param array<int, string> $order
     * @param array{tree: mixed} $conservativeTree
     * @param int $conservativeLength
     * @param bool $local
     */
    private function callSearchOccurrences(
        Document $document,
        array $units,
        array $order,
        array $conservativeTree,
        int $conservativeLength,
        bool $local,
    ): string {
        if ($this->searchOccurrencesCallback !== null) {
            return ($this->searchOccurrencesCallback)($document, $units, $order, $conservativeTree, $conservativeLength, $local);
        }

        return $this->searchOccurrences($document, $units, $order, $conservativeTree, $conservativeLength, $local);
    }

    /**
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param array<int, \MarkupCarve\Carve\Node\Node> $all
     * @param array<int, \MarkupCarve\Carve\Node\Node> $units
     * @param array{tree: mixed} $conservativeTree
     * @param int $conservativeLength
     * @param bool $local
     */
    private function callSearchUnits(
        Document $document,
        array $all,
        array $units,
        array $conservativeTree,
        int $conservativeLength,
        bool $local,
    ): string {
        if ($this->searchUnitsCallback !== null) {
            return ($this->searchUnitsCallback)($document, $all, $units, $conservativeTree, $conservativeLength, $local);
        }

        return $this->searchUnits($document, $all, $units, $conservativeTree, $conservativeLength, $local);
    }

    /**
     * @return array{tree: mixed}|null
     */
    private function callWindowTree(string $source): ?array
    {
        if ($this->windowTreeCallback !== null) {
            return ($this->windowTreeCallback)($source);
        }

        return $this->windowTree($source);
    }
}
