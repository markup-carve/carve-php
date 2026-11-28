<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

/**
 * One pass of a group repair, with what it started from and ended with (carve-js `PassRecord`).
 */
final class PassRecord
{
    /**
     * @var list<array{string, \MarkupCarve\Carve\Writer\Check\Node|null, bool}>|null Where its trials of `wholeTests`
     *     give no tree, the parses their tests make; null where one does. Taken once, when asked.
     */
    public ?array $whole = null;

    public bool $wholeTaken = false;

    /**
     * @param string $step Which step of the repair this records.
     * @param bool $kindRounds
     * @param bool $closerKinds
     * @param bool $wholeTrials
     * @param list<\MarkupCarve\Carve\Writer\Check\Escape>|null $trialLog
     * @param bool $trialsRetested
     * @param bool $retested Where the pass read the log, whether it had retested it at its end.
     * @param \MarkupCarve\Carve\Writer\Check\PassState $start
     * @param \MarkupCarve\Carve\Writer\Check\PassState $end
     * @param \MarkupCarve\Carve\Writer\Check\PassTrace $trace
     * @param array<int, list<int>> $placed What the pass added to `linkPlaced`.
     * @param int $chars
     * @param int $budget The limit of the repair (C2-W-133), which the start state decides.
     */
    public function __construct(
        public readonly string $step,
        public readonly bool $kindRounds,
        public readonly bool $closerKinds,
        public readonly bool $wholeTrials,
        public readonly ?array $trialLog,
        public readonly bool $trialsRetested,
        public readonly bool $retested,
        public readonly PassState $start,
        public readonly PassState $end,
        public readonly PassTrace $trace,
        public readonly array $placed,
        public readonly int $chars,
        public readonly int $budget,
    ) {
    }
}
