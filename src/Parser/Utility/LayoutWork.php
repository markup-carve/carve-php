<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser\Utility;

/**
 * COUNTED instrumentation for the container-layout work (markup-carve/carve#752).
 *
 * Every nesting level hands its body to a nested parse, so a line at depth `d`
 * is handled `d` times. That is the container model and it is not what this
 * measures. What it measures is the CHARACTER work at each of those handlings:
 * while every level re-measured the whole indentation run of every body line,
 * an O(bytes) document cost O(bytes^1.5) of work.
 *
 * The regression guard counts those characters rather than timing them. A count
 * is a property of the algorithm, not of the machine: it reproduces
 * byte-identically under any load, where this repo's own scaling guards have to
 * measure wall-clock cost per byte and carry generous bounds for exactly that
 * reason. carve-js records the same conclusion in its perf tests - a ratio bound
 * tight enough to catch a partial regression "would also fail on the healthy
 * build", and it "flaked on nearly every run".
 *
 * Off by default: a parse pays one static property read per counted call.
 */
final class LayoutWork
{
    /**
     * Whether to accumulate. Tests turn this on around a single parse.
     *
     * @var bool
     */
    public static bool $on = false;

    /**
     * Characters walked by the indentation gate (getLeadingColumns()).
     *
     * @var int
     */
    public static int $gate = 0;

    /**
     * Characters walked, and then copied, by the column strip
     * (stripLeadingColumns()).
     *
     * @var int
     */
    public static int $strip = 0;

    /**
     * Reset every counter.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$gate = 0;
        self::$strip = 0;
    }

    /**
     * Total counted character work.
     *
     * @return int
     */
    public static function total(): int
    {
        return self::$gate + self::$strip;
    }
}
