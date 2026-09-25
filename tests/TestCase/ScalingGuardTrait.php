<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use Closure;
use MarkupCarve\Carve\CarveConverter;
use RuntimeException;

/**
 * Measures how conversion cost changes with input size.
 *
 * Each side processes the same total bytes. The lowest of alternating rounds
 * estimates uncontended CPU cost; garbage collection runs outside the timer.
 */
trait ScalingGuardTrait
{
    /**
     * @var int
     */
    private const SCALE_SMALL_REPEATS = 12500;

    /**
     * @var int
     */
    private const SCALE_LARGE_REPEATS = 50000;

    /**
     * @var int
     */
    private const SCALE_ROUNDS = 3;

    /**
     * Quadratic growth shows ~4x per-byte at this 4x step, so the bound has to
     * sit below 4.0 and above the spread of a shared runner. It was 2.00, which
     * is inside that spread: the same code measured 2.26-2.30x on CI and
     * 0.95-1.11x idle for three BBCode shapes, because a 400000-byte sample
     * leaves cache where a 100000-byte one fits. Raising it trades no quadratic
     * sensitivity, since nothing between 3x and 4x is a shape this can catch.
     *
     * @var float
     */
    private const SCALE_MAX_PER_BYTE_RATIO = 3.0;

    /**
     * @var float
     */
    private const SCALE_MAX_SECONDS = 20.0;

    /**
     * Assert that converting a repeated fragment scales linearly.
     *
     * @param \MarkupCarve\Carve\CarveConverter $converter Converter under test.
     * @param string $fragment Repeated to build both samples.
     * @param string $suffix Appended once to each sample.
     * @param string $label Identifies the shape in failure output.
     * @param int|null $smallRepeats Overrides the sample sizes for block shapes.
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
     * Measure per-byte cost for two sizes of the same input shape.
     *
     * @param \Closure $convert Runs the conversion under test on one input.
     * @param string $small Smaller sample.
     * @param string $large Larger sample, the same shape at a whole multiple.
     * @param string $label Identifies the shape in failure output.
     * @param int $smallRepeats Units in the smaller sample.
     * @param int $largeRepeats Units in the larger sample.
     * @param float|null $maxSeconds Maximum CPU time for either sample.
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

        // Give both windows the same total byte count.
        $batch = max(1, (int)round($largeBytes / max($smallBytes, 1)));

        // Warm per-instance caches before measuring.
        $convert($small);

        $bestSmall = INF;
        $bestLarge = INF;

        for ($round = 0; $round < self::SCALE_ROUNDS; $round++) {
            // Alternate order to limit drift between samples.
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
                    . 'small=%.4fus/byte large=%.4fus/byte. This is process CPU time over %d rounds: '
                    . 'rerun with CARVE_SCALING_REPORT=1 to see every shape, then '
                    . 'repeat the measurement before treating it as a parser regression.',
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
     * @throws \RuntimeException When process CPU time is unavailable.
     *
     * @return float
     */
    private function timeConvert(Closure $convert, string $input, int $times): float
    {
        // Collection cost depends on the rest of the test process, not this input.
        gc_collect_cycles();
        $startCpu = getrusage();
        if (!is_array($startCpu)) {
            throw new RuntimeException('Could not read process CPU time');
        }

        gc_disable();
        try {
            for ($i = 0; $i < $times; $i++) {
                $convert($input);
            }
        } finally {
            $endCpu = getrusage();
            gc_enable();
        }

        if (!is_array($endCpu)) {
            throw new RuntimeException('Could not read process CPU time');
        }

        return $this->cpuSeconds($endCpu) - $this->cpuSeconds($startCpu);
    }

    /**
     * @param array<string, int> $usage
     */
    private function cpuSeconds(array $usage): float
    {
        return $usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']
            + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1e6;
    }
}
