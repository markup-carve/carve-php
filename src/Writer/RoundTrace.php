<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use Closure;

/**
 * A test hook on the writer's repair rounds: the planner trace harness (tests/Writer/PlannerTrace) compares each round
 * with the same round of carve-js. Null outside that harness, so a round pays one null check.
 */
/**
 * @phpstan-type Escapes array<string, array<int, true>>
 */
final class RoundTrace
{
    /**
     * Called once per round with the tree the round writes, the md5 of the written source, the constructs the round
     * reported, its trials and the escapes it placed, each as `array<path, array<offset, true>>`.
     *
     * @var (\Closure(array<mixed>, string, Escapes, Escapes, Escapes): void)|null
     */
    public static ?Closure $hook = null;

    /**
     * The escapes of `$after` that `$before` does not hold.
     *
     * @param array<string, array<int, true>> $before
     * @param array<string, array<int, true>> $after
     *
     * @return array<string, array<int, true>>
     */
    public static function added(array $before, array $after): array
    {
        $added = [];
        foreach ($after as $path => $offsets) {
            foreach ($offsets as $at => $_) {
                if (!isset($before[$path][$at])) {
                    $added[$path][$at] = true;
                }
            }
        }

        return $added;
    }
}
