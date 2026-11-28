<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

use Closure;

/**
 * C2-W-088, C2-W-133 (carve-js write-passes.ts): the passes of one group repair start again and again from the same
 * saved state. A pass whose state and the switches it read are those of an earlier pass of the same repair takes that
 * pass's result instead of running its rounds again. It is charged the parses a rerun would make, and where the
 * recorded work would pass the limit it runs.
 *
 * The snapshot holds the fields of `WriterState` and `Shared` a pass decides (carve-js `state`); `linkPlaced` is only
 * added to (`added`), `kindRounds` is the mode, and `closerKinds`, `wholeTrials`, `trialLog` and `trialsRetested` are
 * the switches a pass may leave unread. A field added to either class that a pass changes must be placed here.
 */
final class PassMemo
{
    /**
     * @var int The switches of a pass that a recorded pass may have left unread (`PassTrace::$reads`).
     */
    public const READ_CLOSER_KINDS = 1;

    /**
     * @var int
     */
    public const READ_WHOLE_TRIALS = 2;

    /**
     * @var int
     */
    public const READ_TRIAL_LOG = 4;

    /**
     * @var int The trials of one pass that are kept for a test; a pass with more runs again.
     */
    private const MAX_WHOLE_TESTS = 8;

    /**
     * @var list<\MarkupCarve\Carve\Writer\Check\PassRecord> The passes this group repair ran.
     */
    private array $records = [];

    /**
     * Runs one pass of the rounds, or another step of the repair, through the memo. `run` returns whether the pass
     * reached a limit of C2-W-133; such a pass is not recorded, since its result depends on the limit left.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param string $step
     * @param \Closure(): bool $run
     */
    public function memoPass(WriterState $w, string $step, Closure $run): void
    {
        $start = PassState::take($w);
        foreach ($this->records as $record) {
            if ($record->step === $step && $this->matches($w, $record, $start) && self::fits($w, $record)) {
                self::replay($w, $record, $start);

                return;
            }
        }
        $outer = $w->pass;
        $chars = $w->shared->chars;
        $trialLog = $w->trialLog;
        $trialsRetested = $w->trialsRetested;
        $linkPlaced = $w->linkPlaced;
        $kindRounds = $w->kindRounds;
        $budget = $w->shared->limit;
        $trace = new PassTrace();
        $w->pass = $trace;
        try {
            $limited = $run();
            if (!$limited) {
                $this->records[] = new PassRecord(
                    $step,
                    $kindRounds,
                    $w->closerKinds,
                    $w->wholeTrials,
                    $trialLog,
                    $trialsRetested,
                    $w->trialsRetested,
                    $start,
                    PassState::take($w),
                    $trace,
                    self::addedSince($linkPlaced, $w->linkPlaced),
                    $w->shared->chars - $chars,
                    $budget,
                );
            }
        } finally {
            if ($outer !== null) {
                self::mergeTrace($outer, $trace);
            }
            $w->pass = $outer;
        }
    }

    /**
     * C2-W-125: a pass without `wholeTrials` stands in for one with it where each trial it read the switch for gives
     * no tree and leaves the writer as `isAsked` puts it back: the pass with the switch then runs the same rounds.
     */
    private static function wholeStandsIn(WriterState $w, PassRecord $record): bool
    {
        if (!$w->wholeTrials || $record->wholeTrials) {
            return false;
        }
        if (!$record->wholeTaken) {
            $record->whole = self::runWholeTests($w, $record->trace->wholeTests);
            $record->wholeTaken = true;
        }

        return $record->whole !== null;
    }

    /**
     * Keeps a trial a pass without `wholeTrials` read the switch for, with the writer as it stands.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \Closure(): bool $gives
     */
    public static function noteWholeTest(WriterState $w, Closure $gives): void
    {
        $pass = $w->pass;
        if ($pass === null || $pass->wholeTests === null) {
            return;
        }
        if (count($pass->wholeTests) >= self::MAX_WHOLE_TESTS) {
            $pass->wholeTests = null;

            return;
        }
        $pass->wholeTests[] = new WholeTest(PassState::take($w), $w->linkPlaced, [$w->shared->parses, $w->shared->chars], $gives);
    }

    /**
     * Runs `givesTree` for each kept trial from the writer as it stood there, on a parse cache and trace of its own,
     * and puts the writer back. The parses it made, or null where a trial gives the tree or changes the writer.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\WholeTest>|null $tests
     *
     * @return list<array{string, \MarkupCarve\Carve\Writer\Check\Node|null, bool}>|null
     */
    private static function runWholeTests(WriterState $w, ?array $tests): ?array
    {
        if ($tests === null) {
            return null;
        }
        if ($tests === []) {
            return [];
        }
        $current = PassState::take($w);
        $linkPlaced = $w->linkPlaced;
        $cache = $w->shared->parseCache;
        $pass = $w->pass;
        $work = [$w->shared->parses, $w->shared->chars];
        $charges = [];
        try {
            foreach ($tests as $test) {
                $test->state->restore($w);
                $w->linkPlaced = $test->linkPlaced;
                [$w->shared->parses, $w->shared->chars] = $test->work;
                $w->shared->parseCache = new ParseCache($cache->kept);
                $w->pass = new PassTrace();
                $idle = !($test->gives)() && !$w->exhausted()
                    && self::sameBesideTrial($test->state, PassState::take($w)) && self::same($test->linkPlaced, $w->linkPlaced);
                if (!$idle) {
                    return null;
                }
                array_push($charges, ...$w->pass->charges);
            }

            return $charges;
        } finally {
            $current->restore($w);
            $w->linkPlaced = $linkPlaced;
            [$w->shared->parses, $w->shared->chars] = $work;
            $w->shared->parseCache = $cache;
            $w->pass = $pass;
        }
    }

    /**
     * Puts the writer where the recorded pass left it, with what the pass added and charged.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\PassRecord $known
     * @param \MarkupCarve\Carve\Writer\Check\PassState $start
     */
    private static function replay(WriterState $w, PassRecord $known, PassState $start): void
    {
        $whole = self::wholeStandsIn($w, $known);
        $known->end->restore($w);
        // The members the start holds beyond the record's are members the pass never found absent (`bracedFits`).
        $w->bracedAnyway += $start->bracedAnyway;
        if ($w->pass !== null) {
            $w->pass->braced += $known->trace->braced;
        }
        foreach ($known->placed as $id => $offsets) {
            foreach ($offsets as $at) {
                $w->linkPlaced[$id][$at] = true;
            }
        }
        foreach ($known->trace->logged as $trials) {
            self::logTrials($w, $trials);
        }
        $w->trialsRetested = $known->retested;
        // The parses the pass would make again are charged as `parseChunk` charges them; a kept tree makes none.
        foreach ($known->trace->charges as [$source, $tree, $cacheable]) {
            self::chargeParse($w, $source, $tree, $cacheable);
        }
        if ($whole) {
            foreach ($known->whole ?? [] as [$source, $tree, $cacheable]) {
                self::chargeParse($w, $source, $tree, $cacheable);
            }
        }
        $w->shared->parses += $known->trace->skipped[0];
        $w->shared->chars += $known->trace->skipped[1];
    }

    /**
     * C2-W-133: what `parseChunk` charges for `source` where a replayed pass of the repair stands for the parse.
     */
    public static function chargeParse(WriterState $w, string $source, ?Node $tree, bool $cacheable): void
    {
        $cache = $w->shared->parseCache;
        $known = $cacheable && array_key_exists($source, $cache->trees);
        if ($known && $cache->trees[$source] !== null) {
            return;
        }
        if (!$known) {
            $w->shared->parses++;
            $w->shared->chars += strlen($source);
        }
        if ($cacheable && $tree !== null) {
            $cache->remember($source, $tree, $known);
        }
    }

    /**
     * Records a parse that a pass of the repair makes, for a pass that stands in for it; the index of the charge.
     */
    public static function noteCharge(WriterState $w, string $source, bool $cacheable): ?int
    {
        if ($w->pass === null) {
            return null;
        }
        $w->pass->charges[] = [$source, null, $cacheable];

        return count($w->pass->charges) - 1;
    }

    /**
     * A short tree that `parseChunk` keeps is kept for the pass that stands in for this one too.
     */
    public static function keepCharged(WriterState $w, ?int $charge, bool $cacheable, Node $parsed): void
    {
        if ($charge !== null && $cacheable && $w->pass !== null && isset($w->pass->charges[$charge])) {
            $w->pass->charges[$charge][1] = $parsed;
        }
    }

    /**
     * A step run inside another reports to it too.
     */
    private static function mergeTrace(PassTrace $outer, PassTrace $inner): void
    {
        $outer->reads |= $inner->reads;
        $logged = count($outer->logged);
        array_push($outer->logged, ...$inner->logged);
        foreach ($inner->checks as $at) {
            $outer->checks[] = $at + $logged;
        }
        array_push($outer->charges, ...$inner->charges);
        $outer->skipped[0] += $inner->skipped[0];
        $outer->skipped[1] += $inner->skipped[1];
        $outer->braced += $inner->braced;
        $outer->wholeTests = $outer->wholeTests === null || $inner->wholeTests === null
            ? null
            : [...$outer->wholeTests, ...$inner->wholeTests];
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param \MarkupCarve\Carve\Writer\Check\PassRecord $record
     * @param \MarkupCarve\Carve\Writer\Check\PassState $start
     */
    private function matches(WriterState $w, PassRecord $record, PassState $start): bool
    {
        if ($record->kindRounds !== $w->kindRounds) {
            return false;
        }
        $reads = $record->trace->reads;
        if (($reads & self::READ_CLOSER_KINDS) !== 0 && $record->closerKinds !== $w->closerKinds) {
            return false;
        }
        $whole = ($reads & self::READ_WHOLE_TRIALS) !== 0 && $record->wholeTrials !== $w->wholeTrials;
        if ($whole && (!$w->wholeTrials || $record->trace->wholeTests === null)) {
            return false;
        }
        if (
            ($reads & self::READ_TRIAL_LOG) !== 0
            && ($record->trialsRetested !== $w->trialsRetested || !self::same($record->trialLog, $w->trialLog))
        ) {
            return false;
        }
        if ($record->trace->checks !== [] && !self::closedAtChecks($w, $record)) {
            return false;
        }

        return self::sameStart($record, $start) && (!$whole || self::wholeStandsIn($w, $record));
    }

    /**
     * @param \MarkupCarve\Carve\Writer\Check\PassRecord $record
     * @param \MarkupCarve\Carve\Writer\Check\PassState $start
     */
    private static function sameStart(PassRecord $record, PassState $start): bool
    {
        $fields = $start->fields();
        foreach ($record->start->fields() as $at => $value) {
            if ($at !== PassState::BRACED && !self::same($value, $fields[$at])) {
                return false;
            }
        }

        return self::same($record->start->bracedAnyway, $start->bracedAnyway) || self::bracedFits($record, $start);
    }

    /**
     * `bracedAnyway` only grows, and a pass after another starts with the pairs that one braced. Its rounds are those
     * of the record where the start holds every member the record's start held, and each further one is a node the
     * recorded pass never found absent: no read or comparison then differs.
     *
     * @param \MarkupCarve\Carve\Writer\Check\PassRecord $record
     * @param \MarkupCarve\Carve\Writer\Check\PassState $start
     */
    private static function bracedFits(PassRecord $record, PassState $start): bool
    {
        $recorded = $record->start->bracedAnyway;
        $held = $start->bracedAnyway;
        if (array_diff_key($recorded, $held) !== []) {
            return false;
        }

        return array_intersect_key(array_diff_key($held, $recorded), $record->trace->braced) === [];
    }

    /**
     * @param array<int, array<int, true>> $before
     * @param array<int, array<int, true>> $after
     *
     * @return array<int, list<int>>
     */
    private static function addedSince(array $before, array $after): array
    {
        $added = [];
        foreach ($after as $id => $offsets) {
            $fresh = array_keys(array_diff_key($offsets, $before[$id] ?? []));
            if ($fresh !== []) {
                $added[$id] = $fresh;
            }
        }

        return $added;
    }

    /**
     * A log that is none, retested or past its cap stays closed to a retest whatever is logged after. Whether the log
     * of `w` would be closed at each check of the recorded pass: it grows by the trials the pass logged before it.
     */
    private static function closedAtChecks(WriterState $w, PassRecord $record): bool
    {
        $saved = $w->trialLog;
        if ($saved === null || $w->trialsRetested) {
            return true;
        }
        try {
            $logged = 0;
            foreach ($record->trace->checks as $check) {
                for (; $logged < $check; $logged++) {
                    self::logInto($w, $record->trace->logged[$logged]);
                }
                if (count($w->trialLog ?? []) <= Round::MAX_RETESTED_TRIALS) {
                    return false;
                }
            }

            return true;
        } finally {
            $w->trialLog = $saved;
        }
    }

    /**
     * Notes a check that found the trial log closed to a retest.
     */
    public static function noteLogClosed(WriterState $w): void
    {
        if ($w->pass !== null) {
            $w->pass->checks[] = count($w->pass->logged);
        }
    }

    /**
     * Whether the recorded work fits what the limit of the repair leaves, so the rounds would not have stopped early.
     */
    private static function fits(WriterState $w, PassRecord $record): bool
    {
        $whole = 0;
        if (self::wholeStandsIn($w, $record)) {
            foreach ($record->whole ?? [] as [$source]) {
                $whole += strlen($source);
            }
        }

        return $record->budget === PHP_INT_MAX || $w->shared->chars + $record->chars + $whole <= $record->budget;
    }

    /**
     * Whether two snapshots hold the same state beside the fields `isAsked` puts back after its trials, which
     * `givesTree` may leave changed (`escapes`, `escaped`, `lines`, `starts`).
     *
     * @param \MarkupCarve\Carve\Writer\Check\PassState $a
     * @param \MarkupCarve\Carve\Writer\Check\PassState $b
     */
    private static function sameBesideTrial(PassState $a, PassState $b): bool
    {
        $fields = $b->fields();
        foreach ($a->fields() as $at => $value) {
            if (!in_array($at, PassState::TRIAL_FIELDS, true) && !self::same($value, $fields[$at])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The trials a pass hands to `logTrials`, in its order: a replayed pass logs them into the log of the pass it
     * stands for.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Escape> $trials
     */
    private static function noteLogged(WriterState $w, array $trials): void
    {
        if ($w->pass !== null) {
            $w->pass->logged[] = $trials;
        }
    }

    /**
     * Notes that the pass read one of its switches (`READ_*`).
     */
    public static function noteRead(WriterState $w, int $read): void
    {
        if ($w->pass !== null) {
            $w->pass->reads |= $read;
        }
    }

    /**
     * Records each trial and escape a `wholeTrials` pass asks for once, in the order the rounds asked for it.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Escape> $trials
     */
    public static function logTrials(WriterState $w, array $trials): void
    {
        self::noteLogged($w, $trials);
        self::logInto($w, $trials);
    }

    /**
     * Adds the trials to the log of a `wholeTrials` pass, each once, up to its cap.
     *
     * @param \MarkupCarve\Carve\Writer\Check\WriterState $w
     * @param list<\MarkupCarve\Carve\Writer\Check\Escape> $trials
     */
    private static function logInto(WriterState $w, array $trials): void
    {
        if ($w->trialLog === null) {
            return;
        }
        foreach ($trials as $trial) {
            // Past the cap the pass is not retested, so the log stops growing and stays short.
            if (count($w->trialLog) > Round::MAX_RETESTED_TRIALS) {
                return;
            }
            $known = false;
            foreach ($w->trialLog as $entry) {
                if ($entry->node === $trial->node && $entry->at === $trial->at) {
                    $known = true;

                    break;
                }
            }
            if (!$known) {
                $w->trialLog[] = $trial;
            }
        }
    }

    /**
     * Whether two copies are the same: arrays by their keys and items (a list in order, a map by key in any order),
     * attempts and escapes by value, nodes and trees by identity.
     */
    private static function same(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $key => $item) {
                if (!array_key_exists($key, $b) || !self::same($item, $b[$key])) {
                    return false;
                }
            }

            return true;
        }
        if ($a instanceof Escape && $b instanceof Escape) {
            return $a->node === $b->node && $a->at === $b->at;
        }
        if ($a instanceof Attempt && $b instanceof Attempt) {
            return $a->step === $b->step && $a->node === $b->node && self::same($a->escapes, $b->escapes);
        }

        return false;
    }
}
