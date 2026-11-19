<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Support;

use Closure;

/**
 * Measures how much faster than linear a piece of work grows, robust to a loaded runner.
 *
 * The large input is timed against the small input run `$factor` times back to back: the
 * same number of bytes through a path that is linear by construction, measured in the same
 * rounds, so load and clock speed cancel out. Linear work gives about 1, quadratic work
 * about `$factor`.
 */
final class Linearity
{
    /**
     * Excess growth above which a check fails. A quadratic path at factor 8 reads about 8.
     */
    public const LIMIT = 3.0;

    /**
     * @param \Closure(): mixed $small
     * @param \Closure(): mixed $large Work on an input `$factor` times the size of `$small`'s.
     * @param int $factor
     * @param int $rounds Samples per attempt; the fastest of each side counts.
     * @param int $attempts Fresh measurements before giving up: noise passes on a retry, a quadratic path never does.
     *
     * @return float The lowest excess growth seen.
     */
    public static function excess(Closure $small, Closure $large, int $factor, int $rounds = 3, int $attempts = 3): float
    {
        $small();
        $large();
        $best = INF;
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $batch = INF;
            $single = INF;
            for ($round = 0; $round < $rounds; $round++) {
                gc_collect_cycles();
                $start = hrtime(true);
                for ($i = 0; $i < $factor; $i++) {
                    $small();
                }
                $batch = min($batch, hrtime(true) - $start);
                gc_collect_cycles();
                $start = hrtime(true);
                $large();
                $single = min($single, hrtime(true) - $start);
            }
            $best = min($best, $single / max(1, $batch));
            if ($best <= self::LIMIT) {
                break;
            }
        }

        return $best;
    }
}
