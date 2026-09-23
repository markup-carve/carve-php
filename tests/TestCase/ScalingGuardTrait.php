<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use Closure;
use MarkupCarve\Carve\CarveConverter;

/**
 * Shared measurement for the inline-scanner scaling guards.
 *
 * These guards exist to catch a reintroduced O(n^2) scan. They compare the cost
 * PER INPUT BYTE of a small and a large sample of the same shape: a linear scan
 * costs the same per byte at any size, and a quadratic one costs the size
 * multiple more, so with a 4x multiple the reading separates 1x from 4x.
 *
 * Three things had to change for that reading to be trustworthy. None of them
 * moves the threshold: it is still 2.0, because a bound that does not flake is
 * worth more than a loose one.
 *
 * THE TWO SAMPLES NOW DO THE SAME AMOUNT OF WORK. Timing one large conversion
 * against one small one compares a long timer window with a short one, and the
 * two are not equally exposed to a busy machine: the short window can fall
 * entirely inside a quiet moment, the long one cannot. That asymmetry does not
 * average out, it BIASES the ratio upward, and it defeats the usual defense of
 * taking a best-of-N. Running the small input `multiple` times inside one timed
 * window makes both windows the same length over the same byte count, so a
 * stall is equally likely to land on either side.
 *
 * THE ESTIMATOR IS THE MINIMUM, NOT THE MEDIAN. Contention only ever makes a
 * conversion slower, so each side's floor across a few rounds is the cleanest
 * available estimate of its true cost, and it converges in very few samples. A
 * median still carries whatever load was present for most of the run.
 *
 * Measured on one machine, 15 trials per estimator on the shape that flaked
 * (`*[b]x[/b] ` through the BBCode formatting pass, markup-carve/carve-php#2232
 * and #2235):
 *
 * - median of 5 over unequal work, the old calibration: 1.398 to 1.798.
 * - minimum of 5 over unequal work: 1.568 to 1.985.
 * - minimum of 3 over equal work, this one: 1.500 to 1.768.
 *
 * The middle row is why the equal-work part is not optional: a best-of-N on
 * unequally sized windows reads HIGHER than the median it replaced, because it
 * finds the small sample's floor far more easily than the large sample's.
 *
 * THE CYCLE COLLECTOR IS HELD OFF THE TIMER. See timeConvert(): this was the
 * largest error of the three, and the only one that got worse the longer the
 * suite ran.
 *
 * Set `CARVE_SCALING_REPORT=1` to print every shape's reading to stderr, pass
 * or fail. A guard going red asks which shapes moved, and only a run that
 * reports them all can answer that.
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
     * Rounds to sample, per side. Three is enough BECAUSE the estimator is a
     * minimum over equal-length windows: the floor is reached quickly, where a
     * median needs enough samples for the loaded ones to be outnumbered.
     *
     * Three rounds of equal work cost slightly less than five rounds of the old
     * unequal work: the small side runs 4 conversions per round instead of 1,
     * and two fewer rounds more than pay for it. `composer test-scaling` went
     * from 250s to 195s over ten runs each. Raising this buys a little more
     * spread reduction at a directly proportional cost.
     *
     * @var int
     */
    private const SCALE_ROUNDS = 3;

    /**
     * Bound on the per-byte ratio. UNCHANGED at 2.0 across this rework: the
     * flakes came from the measurement, not from the threshold, and widening
     * it would have bought quiet by giving up the half of the range where a
     * real regression first shows.
     *
     * A quadratic scan reads ~4.0 here, and the deliberate one used to check
     * that - the #2214 bracket skip taken back out of convertLists() and
     * convertQuotes() - read 3.18 to 3.46 where the same four shapes read
     * 0.57 to 0.65 healthy.
     *
     * The shapes are NOT all flat, and the bound does not pretend otherwise.
     * Most measure ~1.0; the dearest, the BBCode formatting pass over
     * `*[b]x[/b] `, measures about 1.6 because `repairUnwrittenConstructs()`
     * re-parses the document per round - genuinely n^1.7 over a 64x sweep,
     * filed as markup-carve/carve-php#2238. That is the true cost of the shape
     * and not noise, which is why its CI readings of 2.03 and 2.02 were so
     * hard to read: a measurement that lands within its own error of the bound
     * says nothing either way.
     */
    private const SCALE_MAX_PER_BYTE_RATIO = 2.0;

    /**
     * Catastrophic backstop per conversion. The pre-fix O(n^2) scan took
     * minutes at these sizes, so this still catches a full regression outright
     * while leaving headroom for coverage-instrumented CI.
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

        $this->assertConversionScalesLinearly(
            static function (string $input) use ($converter): void {
                $converter->convert($input);
            },
            str_repeat($fragment, $smallRepeats) . $suffix,
            str_repeat($fragment, $largeRepeats) . $suffix,
            $label !== '' ? $label : $fragment,
            $smallRepeats,
            $largeRepeats,
        );
    }

    /**
     * The measurement itself, over any conversion and any pair of inputs.
     *
     * Split out so a shape that is not one fragment repeated - an HTML import
     * whose references and definitions grow in two places at once - is measured
     * by THIS calibration rather than by a second spelling of it.
     *
     * @param \Closure $convert Runs the conversion under test on one input.
     * @param string $small Smaller sample.
     * @param string $large Larger sample, the same shape at a whole multiple.
     * @param string $label Identifies the shape in failure output.
     * @param int $smallRepeats Units in the smaller sample.
     * @param int $largeRepeats Units in the larger sample.
     * @param float|null $maxSeconds Overrides the catastrophic backstop for
     *   this shape only. The default sits above every INLINE scan's largest
     *   sample; a shape whose healthy cost is dominated by a large LINEAR
     *   constant - a nested-container walk re-entered once per nesting level -
     *   needs a sample big enough for the quadratic term to separate, and at
     *   that size a healthy run is already seconds. Raising it is not
     *   weakening the guard: the RATIO is what discriminates, and the shape
     *   that needs this states its own measured numbers on both sides.
     *
     * @return void
     */
    protected function assertConversionScalesLinearly(
        Closure $convert,
        string $small,
        string $large,
        string $label,
        int $smallRepeats,
        int $largeRepeats,
        ?float $maxSeconds = null,
    ): void {
        $maxSeconds ??= self::SCALE_MAX_SECONDS;
        $smallBytes = strlen($small);
        $largeBytes = strlen($large);

        // How many small conversions make one timed window the same size as the
        // large one's. Taken from BYTES rather than repeats so a shape whose
        // unit is not one line still balances exactly.
        $batch = max(1, (int)round($largeBytes / max($smallBytes, 1)));

        // Prime any per-instance caches so round 1 does not measure setup. The
        // small sample is the same shape as the large one, so it warms the same
        // caches; priming with the large sample as well bought nothing and cost
        // a full 50000-repeat convert per data set.
        $convert($small);

        $bestSmall = INF;
        $bestLarge = INF;

        for ($round = 0; $round < self::SCALE_ROUNDS; $round++) {
            // ALTERNATE which side is timed first, so neither is systematically
            // measured later than the other while load ramps during the test.
            if ($round % 2 === 0) {
                $elapsedSmall = $this->timeConvert($convert, $small, $batch);
                $elapsedLarge = $this->timeConvert($convert, $large, 1);
            } else {
                $elapsedLarge = $this->timeConvert($convert, $large, 1);
                $elapsedSmall = $this->timeConvert($convert, $small, $batch);
            }

            $bestSmall = min($bestSmall, $elapsedSmall / ($batch * $smallBytes));
            $bestLarge = min($bestLarge, $elapsedLarge / $largeBytes);
        }

        $shape = $label;

        $this->assertLessThan(
            $maxSeconds,
            $bestSmall * $smallBytes,
            sprintf('%dx %s took %.3fs (quadratic regression?)', $smallRepeats, $shape, $bestSmall * $smallBytes),
        );
        $this->assertLessThan(
            $maxSeconds,
            $bestLarge * $largeBytes,
            sprintf('%dx %s took %.3fs (quadratic regression?)', $largeRepeats, $shape, $bestLarge * $largeBytes),
        );

        $ratio = $bestLarge / max($bestSmall, PHP_FLOAT_EPSILON);
        $multiple = intdiv($largeRepeats, $smallRepeats);

        if (getenv('CARVE_SCALING_REPORT') !== false) {
            fprintf(
                STDERR,
                "\nSCALING %-56s ratio %.3f / %.2f  small %.4fus/B large %.4fus/B\n",
                $shape,
                $ratio,
                self::SCALE_MAX_PER_BYTE_RATIO,
                $bestSmall * 1e6,
                $bestLarge * 1e6,
            );
        }

        $this->assertLessThan(
            self::SCALE_MAX_PER_BYTE_RATIO,
            $ratio,
            sprintf(
                'Per-byte cost grew %.2fx for %s at %dx the input (bound %.2f, quadratic ~%dx): '
                    . 'small=%.4fus/byte large=%.4fus/byte. This is wall clock over %d rounds: '
                    . 'rerun with CARVE_SCALING_REPORT=1 to see every shape, and reproduce on an '
                    . 'idle machine before concluding the parser got slower.',
                $ratio,
                $shape,
                $multiple,
                self::SCALE_MAX_PER_BYTE_RATIO,
                $multiple,
                $bestSmall * 1e6,
                $bestLarge * 1e6,
                self::SCALE_ROUNDS,
            ),
        );
    }

    /**
     * One timed window, in seconds, over `$times` conversions of one input.
     *
     * @param \Closure $convert Runs the conversion under test on one input.
     * @param string $input Input to convert.
     * @param int $times Conversions inside the window.
     *
     * @return float
     */
    private function timeConvert(Closure $convert, string $input, int $times): float
    {
        // COLLECT OUTSIDE THE TIMER, so a cycle-collector pass over the whole
        // suite's heap cannot land inside one side's window. PHP triggers a
        // pass every 10000 buffered roots, so the larger sample triggers
        // proportionally more of them, and each pass costs in proportion to
        // everything ALIVE in the process - including the documents every
        // earlier scaling test is still holding. That reads as the large
        // sample being dearer per byte, which is precisely what this guard
        // takes as evidence of a quadratic scan.
        //
        // Measured on `\_` through the Markdown renderer, whose true per-byte
        // cost is flat (0.8788us/byte at 12500 repeats, 0.9013 at 50000, a
        // ratio of 1.03): run alone it read 1.30-1.39, run in its place in the
        // suite it read 1.62-1.97 against a 2.0 bound. The shape never changed;
        // the heap around it did.
        gc_collect_cycles();
        gc_disable();

        $start = hrtime(true);
        for ($i = 0; $i < $times; $i++) {
            $convert($input);
        }
        $elapsed = (hrtime(true) - $start) / 1e9;

        gc_enable();

        return $elapsed;
    }
}
