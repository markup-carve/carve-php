<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * Hands the heap pages a finished parse or write left empty back to the allocator (carve#6413).
 *
 * PHP keeps a page of freed small blocks reserved for their size until `gc_mem_caches()`. A tree encoded right
 * after a parse, or a source joined right after a write, otherwise spreads over the pages the parse or write left
 * partly used and takes new 2 MB chunks for the rest: 8 MB of allocated heap on a deeply nested 128 KB paragraph.
 */
final class HeapPages
{
    /**
     * Unused heap worth the walk over the allocator's free lists.
     *
     * @var int
     */
    public const UNUSED = 16 * 1024 * 1024;

    /**
     * The allocated and the unused heap after the last release, or the least of each seen since. A long-lived host keeps
     * the chunks of its largest call; while the heap takes no new chunk and frees no more than the threshold, a release
     * would cost every call a walk over the allocator's free lists.
     *
     * @var array{int, int}
     */
    private static array $after = [0, 0];

    /**
     * Whether the free lists were handed back.
     *
     * @param int $threshold Unused heap, or heap freed since the last release, worth the walk.
     */
    public static function release(int $threshold = self::UNUSED): bool
    {
        $allocated = memory_get_usage(true);
        $unused = $allocated - memory_get_usage();
        [$allocatedAfter, $unusedAfter] = self::$after;
        if ($unused <= $threshold || ($allocated <= $allocatedAfter && $unused - $unusedAfter <= $threshold)) {
            self::$after = [min($allocatedAfter, $allocated), min($unusedAfter, $unused)];

            return false;
        }
        gc_mem_caches();
        $allocated = memory_get_usage(true);
        self::$after = [$allocated, $allocated - memory_get_usage()];

        return true;
    }
}
