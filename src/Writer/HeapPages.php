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
    private const UNUSED = 16 * 1024 * 1024;

    /**
     * The allocated heap after the last release, or the least seen since. A long-lived host keeps the chunks of its
     * largest call; until the heap takes a new chunk those hold the call, and a release would cost every call a walk
     * over the allocator's free lists.
     *
     * @var int
     */
    private static int $allocated = 0;

    /**
     * Whether the free lists were handed back.
     */
    public static function release(): bool
    {
        $allocated = memory_get_usage(true);
        if ($allocated <= self::$allocated || $allocated - memory_get_usage() <= self::UNUSED) {
            self::$allocated = min(self::$allocated, $allocated);

            return false;
        }
        gc_mem_caches();
        self::$allocated = memory_get_usage(true);

        return true;
    }
}
