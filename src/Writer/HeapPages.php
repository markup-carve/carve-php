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
     * Unused heap worth the walk over the allocator's free lists, as `Linter` and `DataModel` use it.
     *
     * @var int
     */
    private const UNUSED = 16 * 1024 * 1024;

    public static function release(): void
    {
        if (memory_get_usage(true) - memory_get_usage() > self::UNUSED) {
            gc_mem_caches();
        }
    }
}
