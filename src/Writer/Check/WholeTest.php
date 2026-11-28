<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer\Check;

use Closure;

/**
 * A trial a pass without `wholeTrials` read the switch for, which a pass with it would test (carve-js `WholeTest`).
 */
final class WholeTest
{
    /**
     * @param \MarkupCarve\Carve\Writer\Check\PassState $state
     * @param array<int, array<int, true>> $linkPlaced
     * @param array{int, int} $work The parses and characters at the trial.
     * @param \Closure(): bool $gives `givesTree` for the trial, as the pass with the switch runs it.
     */
    public function __construct(
        public readonly PassState $state,
        public readonly array $linkPlaced,
        public readonly array $work,
        public readonly Closure $gives,
    ) {
    }
}
