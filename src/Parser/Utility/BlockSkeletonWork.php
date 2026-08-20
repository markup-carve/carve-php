<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser\Utility;

use MarkupCarve\Carve\Parser\BlockSkeleton;

/**
 * Opt-in shadow instrumentation for the materialized block skeleton.
 *
 * Production parsing pays one static boolean check per parseBlocks() frame and
 * no event allocation while this remains disabled.
 */
final class BlockSkeletonWork
{
    public static bool $on = false;

    public static ?BlockSkeleton $last = null;

    public static function reset(): void
    {
        self::$last = null;
    }
}
