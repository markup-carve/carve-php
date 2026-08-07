<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;

/**
 * Shared measurement for the inline-scanner scaling guards.
 *
 * These guards exist to catch a reintroduced O(n^2) scan. They used to compare
 * two total wall-clock durations taken back to back and require the larger to
 * be under 3x the smaller. That is mis-calibrated rather than merely unlucky:
 * a healthy scan measures ~2x when the input doubles, so the threshold sat only
 * 1.5x above the expected value, and either sample could be taken while the
 * runner was busy. Observed CI failures of 3.32x and 4.39x were ordinary CPU
 * contention, not regressions.
 *
 * Three changes make the assertion robust without weakening it:
 *
 * - Compare cost PER INPUT BYTE, not total elapsed. "Linear" means the per-byte
 *   cost is constant as the input grows, so a healthy scan measures ~1 whatever
 *   the size multiple is, and a quadratic scan measures the size multiple
 *   itself. With a 4x multiple the threshold now sits midway between 1 and 4
 *   instead of between 2 and 4.
 * - INTERLEAVE the two sizes. Timing all the small runs and then all the large
 *   runs lets a runner that is busy for only part of the test skew one side of
 *   the ratio; alternating them means load drift hits both.
 * - Take the MEDIAN of several rounds. A mean is still dragged by one stall,
 *   and a minimum discards the information that the machine was loaded at all.
 */
trait ScalingGuardTrait
{
    /**
     * Input byte counts for the two samples. A 4x multiple separates linear
     * (~1x per-byte) from quadratic (~4x per-byte) far more cleanly than the
     * doubling this replaced, and costs less total work than the old
     * 25000/50000 pair did.
     *
     * @var int
     */
    private const SCALE_SMALL_REPEATS = 12500;

    /**
     * @var int
     */
    private const SCALE_LARGE_REPEATS = 50000;

    /**
     * Rounds to sample. Interleaving with an alternating order cancels load
     * drift -- a stall lands on both sizes, and neither size is systematically
     * measured later -- and the median then discards a round that stalled
     * unevenly. Five rounds (an odd count, so the median is a real sample)
     * survived both suites running concurrently.
     *
     * Deliberately NOT reduced to buy runtime. These 49 data sets used to be
     * most of the default suite's wall clock, but they now run as their own
     * `scaling` group on a runner of their own, so what this count costs is off
     * the critical path -- and a smaller sample is exactly what makes the ratio
     * flaky on a loaded machine, which is the failure this calibration was
     * chosen to end.
     *
     * @var int
     */
    private const SCALE_ROUNDS = 5;

    /**
     * A healthy scan measures ~1.0 and the worst real shape measured 1.21; a
     * quadratic scan measures ~4.0 (the size multiple). Sitting at 2.0 leaves
     * roughly a 1.65x margin above the noisiest healthy shape and a 2x margin
     * below a genuine regression.
     */
    private const SCALE_MAX_PER_BYTE_RATIO = 2.0;

    /**
     * Catastrophic backstop per sample. The pre-fix O(n^2) scan took minutes at
     * these sizes, so this still catches a full regression outright while
     * leaving headroom for coverage-instrumented CI.
     */
    private const SCALE_MAX_SECONDS = 20.0;

    /**
     * Assert that converting a repeated fragment scales linearly.
     *
     * @param \MarkupCarve\Carve\CarveConverter $converter Converter under test.
     * @param string $fragment Repeated to build both samples.
     * @param string $suffix Appended once to each sample.
     * @param string $label Identifies the shape in failure output.
     * @param int|null $smallRepeats Overrides the small sample's repeat count;
     *   the large sample keeps the same 4x multiple. A BLOCK-level shape is
     *   several lines per repeat, so the inline default builds a document two
     *   orders of magnitude larger than the shape needs to separate linear from
     *   quadratic. Everything else about the measurement stays shared - a
     *   second spelling of the timing is exactly what this trait exists to
     *   prevent.
     *
     * @return void
     */
    protected function assertScanScalesLinearly(
        CarveConverter $converter,
        string $fragment,
        string $suffix = '',
        string $label = '',
        ?int $smallRepeats = null,
    ): void {
        $smallRepeats ??= self::SCALE_SMALL_REPEATS;
        $largeRepeats = $smallRepeats * intdiv(self::SCALE_LARGE_REPEATS, self::SCALE_SMALL_REPEATS);
        $small = str_repeat($fragment, $smallRepeats) . $suffix;
        $large = str_repeat($fragment, $largeRepeats) . $suffix;

        $smallBytes = strlen($small);
        $largeBytes = strlen($large);

        // Prime any per-instance caches so round 1 does not measure setup. The
        // small sample is the same shape as the large one, so it warms the same
        // caches; priming with the large sample as well bought nothing and cost
        // a full 50000-repeat convert per data set.
        $converter->convert($small);

        $smallPerByte = [];
        $largePerByte = [];
        $worstSmall = 0.0;
        $worstLarge = 0.0;

        for ($round = 0; $round < self::SCALE_ROUNDS; $round++) {
            // ALTERNATE which size is timed first. Interleaving alone still
            // leaves an ordering bias: within a round the second sample is
            // always taken later, so a load that ramps during the test pushes
            // it up systematically. Swapping the order every round cancels
            // that -- observed as a 2.59x reading for a shape that measures a
            // flat 1.00-1.04x across a 16x range when run alone.
            if ($round % 2 === 0) {
                $elapsedSmall = $this->timeConvert($converter, $small);
                $elapsedLarge = $this->timeConvert($converter, $large);
            } else {
                $elapsedLarge = $this->timeConvert($converter, $large);
                $elapsedSmall = $this->timeConvert($converter, $small);
            }

            $smallPerByte[] = $elapsedSmall / $smallBytes;
            $largePerByte[] = $elapsedLarge / $largeBytes;

            $worstSmall = max($worstSmall, $elapsedSmall);
            $worstLarge = max($worstLarge, $elapsedLarge);
        }

        $shape = $label !== '' ? $label : $fragment;

        $this->assertLessThan(
            self::SCALE_MAX_SECONDS,
            $worstSmall,
            sprintf('%dx %s took %.3fs (quadratic regression?)', $smallRepeats, $shape, $worstSmall),
        );
        $this->assertLessThan(
            self::SCALE_MAX_SECONDS,
            $worstLarge,
            sprintf('%dx %s took %.3fs (quadratic regression?)', $largeRepeats, $shape, $worstLarge),
        );

        $medianSmall = $this->median($smallPerByte);
        $medianLarge = $this->median($largePerByte);

        $ratio = $medianLarge / max($medianSmall, PHP_FLOAT_EPSILON);
        $multiple = intdiv($largeRepeats, $smallRepeats);

        $this->assertLessThan(
            self::SCALE_MAX_PER_BYTE_RATIO,
            $ratio,
            sprintf(
                'Per-byte cost grew %.2fx for %s at %dx the input (linear ~1x, quadratic ~%dx): '
                    . 'small=%.4fus/byte large=%.4fus/byte',
                $ratio,
                $shape,
                $multiple,
                $multiple,
                $medianSmall * 1e6,
                $medianLarge * 1e6,
            ),
        );
    }

    /**
     * One timed convert(), in seconds.
     *
     * @param \MarkupCarve\Carve\CarveConverter $converter Converter under test.
     * @param string $input Input to convert.
     *
     * @return float
     */
    private function timeConvert(CarveConverter $converter, string $input): float
    {
        $start = hrtime(true);
        $converter->convert($input);

        return (hrtime(true) - $start) / 1e9;
    }

    /**
     * @param array<int, float> $values Non-empty sample list.
     *
     * @return float
     */
    private function median(array $values): float
    {
        sort($values);

        return $values[intdiv(count($values), 2)];
    }
}
