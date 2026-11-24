<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

/**
 * C2-W-146: one text node or soft break of a content as written, for the pair test of a run. An object, not a keyed
 * array: a long paragraph holds one per node, and the array costs about three times the memory (carve-php#5664).
 */
final class RunEntry
{
    /**
     * @param string $path
     * @param string $out The node as written.
     * @param int $prefix The bytes of `$out` written before the text's value.
     * @param array<int, bool|int> $escaped Whether each byte offset of `$value` took a backslash, or how many.
     * @param string $value
     * @param array<int, string> $kinds The kinds of the repair escapes of the write.
     */
    public function __construct(
        public readonly string $path,
        public readonly string $out,
        public readonly int $prefix,
        public readonly array $escaped,
        public readonly string $value,
        public readonly array $kinds = [],
    ) {
    }
}
